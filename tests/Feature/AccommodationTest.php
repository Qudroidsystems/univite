<?php

use App\Models\AccommodationApplication;
use App\Models\BedAllocation;
use App\Models\Hostel;
use App\Models\Invoice;
use App\Services\Accommodation\AccommodationService;
use App\Services\Accommodation\AccommodationSettings;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 09:00:00');
    campusWorld($this);
    $this->officer = makeStaff($this, 'Hall Officer', null, ['View accommodation', 'Set accommodation policy', 'Allocate accommodation', 'Check students in and out of hostels']);
    app(AccommodationSettings::class)->save(['applications_open' => 1, 'payment_days' => 7, 'priority' => 'applied', 'any_hostel' => 1]);

    $this->actingAs($this->officer)->post(route('hostels.store'), ['name' => 'Queen Amina Hall', 'gender' => 'female', 'rate' => 30000, 'is_active' => 1, 'audience' => ['who' => 'students']])->assertRedirect();
    $this->amina = Hostel::where('name', 'Queen Amina Hall')->sole();
    $this->post(route('hostels.rooms.store', $this->amina), ['block' => 'A', 'from' => 101, 'to' => 101, 'capacity' => 2])->assertSessionHas('success', '1 room(s) added.');

    $this->post(route('hostels.store'), ['name' => 'Freshers Hall', 'gender' => 'mixed', 'rate' => 0, 'is_active' => 1, 'audience' => ['who' => 'students', 'levels' => [100]]]);
    $this->freshers = Hostel::where('name', 'Freshers Hall')->sole();
    $this->post(route('hostels.rooms.store', $this->freshers), ['from' => 1, 'to' => 2, 'capacity' => 1]);
});

afterEach(fn () => Carbon::setTestNow());

it('allocates by application order, holds the bed until paid, and confirms it when the invoice is paid', function () {
    $ada = makeStudent($this, 'Ada', 'female', 300);
    $bisi = makeStudent($this, 'Bisi', 'female', 300);
    $chi = makeStudent($this, 'Chioma', 'female', 300);
    $tunde = makeStudent($this, 'Tunde', 'male', 300);

    // Tunde cannot choose a female hall; 300 level cannot choose the freshers' hall.
    $this->actingAs($tunde->user)->get(route('my.accommodation'))->assertOk()->assertSee('No hostel is open to you');
    foreach ([$ada, $bisi, $chi] as $i => $s) {
        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($s->user)->post(route('my.accommodation.apply'), ['preferences' => [$this->amina->id, $this->freshers->id]])->assertSessionHas('success');
    }
    expect(AccommodationApplication::where('student_id', $ada->id)->sole()->preferences)->toBe([$this->amina->id]);

    $this->actingAs($this->officer)->post(route('accommodation.run'))->assertSessionHas('success', '2 student(s) given a bed, 1 could not be placed.');
    $a = BedAllocation::where('student_id', $ada->id)->sole();
    expect($a->status)->toBe('reserved')->and($a->bed)->toBe(1)->and($a->reserved_until->toDateString())->toBe('2026-11-09')
        ->and($a->invoice->kind)->toBe('accommodation')->and((float) $a->invoice->total)->toBe(30000.0)
        ->and(AccommodationApplication::where('student_id', $chi->id)->value('status'))->toBe('unsuccessful');

    $this->actingAs($ada->user)->get(route('my.accommodation'))->assertOk()->assertSee('Queen Amina Hall')->assertSee('Pay ₦30,000.00 by');

    // Paying the invoice confirms the bed; the warden can then check her in.
    app(InvoiceService::class)->recordOfflinePayment($a->invoice, 30000, 'bank', 'TELLER-1', '2026-11-03', null, $this->officer->id);
    expect($a->fresh()->status)->toBe('confirmed');
    $this->actingAs($this->officer)->post(route('accommodation.check-in', $a))->assertSessionHas('success');
    expect($a->fresh()->status)->toBe('checked_in');

    // Bisi does not pay: her bed is released after the deadline and can go to Chioma.
    $b = BedAllocation::where('student_id', $bisi->id)->sole();
    $this->post(route('accommodation.check-in', $b))->assertSessionHas('error', 'This bed has not been paid for yet.');
    Carbon::setTestNow('2026-11-10 07:00:00');
    expect(app(AccommodationService::class)->releaseExpired())->toBe(1)
        ->and($b->fresh()->status)->toBe('released')->and($b->invoice->fresh()->status)->toBe('cancelled');
    $this->post(route('accommodation.place'), ['person' => $chi->matric_number, 'hostel_id' => $this->amina->id])->assertSessionHas('success', fn ($m) => str_contains($m, 'Room 101, bed 2'));
});

it('places freshers in a free hall straight away, and handles moves, cancellations and full rooms', function () {
    $f1 = makeStudent($this, 'Femi', 'male', 100);
    $f2 = makeStudent($this, 'Gbenga', 'male', 100);
    $this->actingAs($this->officer)->post(route('accommodation.place'), ['person' => $f1->matric_number, 'hostel_id' => $this->freshers->id, 'room' => '1'])->assertSessionHas('success');
    $a = BedAllocation::where('student_id', $f1->id)->sole();
    expect($a->status)->toBe('confirmed')->and($a->invoice_id)->toBeNull();

    $this->post(route('accommodation.place'), ['person' => $f2->matric_number, 'hostel_id' => $this->freshers->id, 'room' => '1'])->assertSessionHas('error', fn ($m) => str_contains($m, 'is full'));
    $this->post(route('accommodation.place'), ['person' => $f2->matric_number, 'hostel_id' => $this->amina->id])->assertSessionHas('error', fn ($m) => str_contains($m, 'for other students'));
    $this->post(route('accommodation.place'), ['person' => $f2->matric_number, 'hostel_id' => $this->freshers->id])->assertSessionHas('success', fn ($m) => str_contains($m, 'Room 2'));

    $b = BedAllocation::where('student_id', $f2->id)->sole();
    $this->post(route('accommodation.move', $a), ['hostel_id' => $this->freshers->id, 'room' => '2', 'reason' => 'Swap'])->assertSessionHas('error', fn ($m) => str_contains($m, 'full'));
    $this->post(route('accommodation.cancel', $b), ['reason' => 'Left the hostel'])->assertSessionHas('success');
    $this->post(route('accommodation.move', $a), ['hostel_id' => $this->freshers->id, 'room' => '2', 'reason' => 'Plumbing in room 1'])->assertSessionHas('success');
    expect($a->fresh()->room->name)->toBe('2');

    $this->get(route('hostels.index'))->assertOk()->assertSee('Queen Amina Hall');
    $this->get(route('hostels.show', $this->freshers))->assertOk()->assertSee('Student Femi');
    $this->get(route('accommodation.allocations'))->assertOk()->assertSee('Student Femi');
    $this->get(route('accommodation.allocations', ['tab' => 'applications']))->assertOk();
    $this->get(route('accommodation.settings'))->assertOk();
    $this->actingAs($f1->user)->get(route('hostels.index'))->assertForbidden();
});

it('puts new students first when the rules say so', function () {
    app(AccommodationSettings::class)->save(['priority' => 'new_first']);
    $old = makeStudent($this, 'Old', 'female', 400);
    $new = makeStudent($this, 'New', 'female', 100);
    $this->actingAs($this->officer)->put(route('hostels.rooms.update', $this->amina->rooms()->first()), ['capacity' => 1, 'is_active' => 1])->assertSessionHas('success');
    $this->actingAs($old->user)->post(route('my.accommodation.apply'), ['preferences' => [$this->amina->id]]);
    Carbon::setTestNow(now()->addHour());
    $this->actingAs($new->user)->post(route('my.accommodation.apply'), ['preferences' => [$this->amina->id]]);
    app(AccommodationSettings::class)->save(['any_hostel' => 0]);
    $this->actingAs($this->officer)->post(route('accommodation.run'));
    expect(BedAllocation::sole()->student_id)->toBe($new->id);
});
