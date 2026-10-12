<?php

use App\Models\HealthRecord;
use App\Models\IdCard;
use App\Models\Invoice;
use App\Services\IdCards\IdCardSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 09:00:00');
    Storage::fake('public');
    campusWorld($this);
    $this->ada = makeStudent($this, 'Ada', 'female', 100, null, ['photo' => 'students/ada.jpg']);
    $this->bayo = makeStudent($this, 'Bayo', 'male', 100); // no photograph yet
    $this->clerk = makeStaff($this, 'Card Clerk', null, ['View identity cards', 'Issue identity cards', 'Cancel identity cards', 'Set identity card design']);
});

afterEach(fn () => Carbon::setTestNow());

it('issues cards to a group, skipping people without a photograph, and prints them with a QR check link', function () {
    HealthRecord::create(['user_id' => $this->ada->user_id, 'blood_group' => 'O+', 'genotype' => 'AA']);
    $this->actingAs($this->clerk)->put(route('id-cards.settings.save'), array_merge(app(IdCardSettings::class)->all(), ['serial_prefix' => 'AFUED', 'show_blood_group' => 1]))->assertSessionHas('success');

    $this->post(route('id-cards.batch'), ['audience' => ['who' => 'students', 'levels' => [100]], 'only_without' => 1])
        ->assertSessionHas('success', fn ($m) => str_contains($m, '1 card(s) issued'))
        ->assertSessionHas('skipped', fn ($s) => count($s) === 1 && str_contains($s[0], 'no passport photograph'));

    $card = IdCard::where('user_id', $this->ada->user_id)->sole();
    expect($card->status)->toBe('active')->and($card->serial)->toBe('AFUED/26/' . sprintf('%06d', $card->id))
        ->and($card->snap('blood'))->toBe('Blood group O+ · Genotype AA')
        ->and($card->expires_on->format('Y-m-d'))->toBe('2030-09-30'); // a 4-year course from 100 level

    // Issuing again for the same group gives nobody a second card.
    $this->post(route('id-cards.batch'), ['audience' => ['who' => 'students'], 'only_without' => 1])->assertSessionHas('success', fn ($m) => str_contains($m, '0 card(s) issued, 1 already had'));

    $this->get(route('id-cards.index'))->assertOk()->assertSee($card->serial)->assertSee('Print 1 unprinted');
    $this->get(route('id-cards.print'))->assertOk()->assertSee('Student Ada')->assertSee(route('id-card.check', $card->token), false);
    $this->post(route('id-cards.printed'), ['ids' => [$card->id]])->assertSessionHas('success', '1 card(s) marked printed.');
    $this->post(route('id-cards.collected', $card))->assertSessionHas('success');
    expect($card->fresh()->collected_at)->not->toBeNull();

    // Anyone scanning the card sees it is valid, without signing in.
    auth()->logout();
    $this->get(route('id-card.check', $card->token))->assertOk()->assertSee('VALID')->assertSee('Student Ada');
    $this->get('/id/' . str_repeat('x', 32))->assertOk()->assertSee('NOT VALID')->assertSee('not a card issued');
});

it('handles a lost card: the student asks, pays the fee, gets a new card and the old one stops scanning as valid', function () {
    app(IdCardSettings::class)->save(['replacement_fee' => 2500]);
    $this->actingAs($this->clerk)->post(route('id-cards.issue'), ['person' => $this->ada->matric_number])->assertRedirect();
    $old = IdCard::where('user_id', $this->ada->user_id)->sole();

    $this->actingAs($this->ada->user)->get(route('my.id-card'))->assertOk()->assertSee($old->serial);
    $this->post(route('my.id-card.request'), ['reason' => 'lost', 'note' => 'Lost at the sports complex'])->assertSessionHas('success', fn ($m) => str_contains($m, 'Pay the replacement fee'));
    $request = IdCard::where('user_id', $this->ada->user_id)->where('status', 'requested')->sole();
    expect($request->invoice->kind)->toBe('id_card')->and((float) $request->invoice->total)->toBe(2500.0);

    // Not issued until the fee is paid.
    $this->actingAs($this->clerk)->post(route('id-cards.approve', $request))->assertSessionHas('error', fn ($m) => str_contains($m, 'has not been paid'));
    $request->invoice->update(['status' => 'paid', 'amount_paid' => 2500]);
    $this->post(route('id-cards.approve', $request))->assertRedirect();

    expect($old->fresh()->status)->toBe('replaced')->and($request->fresh()->status)->toBe('active')->and($request->fresh()->reason)->toBe('lost');
    $this->get(route('id-card.check', $old->token))->assertSee('NOT VALID')->assertSee('replaced by a newer card');
    $this->get(route('id-card.check', $request->token))->assertSee('VALID');

    // Cancelling makes it fail the check; a student who leaves fails it too.
    $this->post(route('id-cards.cancel', $request->fresh()), ['reason' => 'Reported stolen'])->assertSessionHas('success');
    $this->get(route('id-card.check', $request->token))->assertSee('has been cancelled');
});

it('lets a student withdraw a request, which cancels its unpaid fee', function () {
    app(IdCardSettings::class)->save(['replacement_fee' => 1000]);
    $this->actingAs($this->clerk)->post(route('id-cards.issue'), ['person' => $this->ada->matric_number]);
    $this->actingAs($this->ada->user)->post(route('my.id-card.request'), ['reason' => 'damaged']);
    $invoice = IdCard::where('status', 'requested')->sole()->invoice;
    $this->post(route('my.id-card.withdraw'))->assertSessionHas('success');
    expect(IdCard::where('status', 'requested')->count())->toBe(0)->and($invoice->fresh()->status)->toBe('cancelled');
});

it('issues staff cards once a photograph is uploaded, and keeps the register to permitted users', function () {
    $lecturer = makeStaff($this, 'Grace Lecturer');
    $this->actingAs($this->clerk)->post(route('id-cards.issue'), ['person' => $lecturer->email])->assertSessionHas('error', fn ($m) => str_contains($m, 'no passport photograph'));
    $this->post(route('id-cards.photo', $lecturer), ['photo' => UploadedFile::fake()->image('g.jpg', 300, 400)])->assertSessionHas('success');
    $this->post(route('id-cards.issue'), ['person' => $lecturer->staffProfile->staff_number])->assertRedirect();
    $card = IdCard::where('user_id', $lecturer->id)->sole();
    expect($card->kind)->toBe('staff')->and($card->expires_on->format('Y-m-d'))->toBe('2031-11-02');

    $this->actingAs($lecturer)->get(route('id-cards.index'))->assertForbidden();
    $this->get(route('my.id-card'))->assertOk()->assertSee($card->serial);
    $this->get(route('id-cards.settings'))->assertForbidden();
    $this->actingAs($this->clerk)->get(route('id-cards.settings'))->assertOk();
});
