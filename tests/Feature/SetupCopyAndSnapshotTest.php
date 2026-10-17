<?php

use App\Data\Registration\RegistrationContext;
use App\Data\Sessions\SemesterPair;
use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\GradeBand;
use App\Models\OperationBatch;
use App\Models\PeriodSetting;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\Setting;
use App\Models\SetupSnapshot;
use App\Models\User;
use App\Services\RegistrationSettings;
use App\Services\SessionCloneService;
use App\Services\Setup\SetupSnapshots;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $this->first = SemesterType::orderBy('sort_order')->first();
    $this->second = SemesterType::orderBy('sort_order')->skip(1)->first();
    $this->src = AcademicSession::create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-08-31', 'status' => 'active']);
    $this->s1 = Semester::create(['academic_session_id' => $this->src->id, 'name' => $this->first->name, 'sort_order' => $this->first->sort_order, 'start_date' => '2025-09-01',
        'registration_opens_at' => '2025-09-10 08:00:00', 'registration_closes_at' => '2025-10-10 17:00:00']);
    $this->s2 = Semester::create(['academic_session_id' => $this->src->id, 'name' => $this->second->name, 'sort_order' => $this->second->sort_order, 'start_date' => '2026-02-01']);
    $this->dst = AcademicSession::create(['name' => '2026/2027']);
    $this->d1 = Semester::create(['academic_session_id' => $this->dst->id, 'name' => $this->first->name, 'sort_order' => $this->first->sort_order]);
    $this->ctx = fn (AcademicSession $s, SemesterType $t) => new RegistrationContext($s, $t, $s->semesters()->where('sort_order', $t->sort_order)->first());
});

it('lets a session and a semester set their own registration settings, the most specific winning', function () {
    Setting::put('registration.open', '1');
    PeriodSetting::create(['academic_session_id' => $this->src->id, 'semester_type_id' => null, 'group' => 'registration', 'values' => ['open' => '0', 'late_registration_days' => '9']]);
    PeriodSetting::create(['academic_session_id' => $this->src->id, 'semester_type_id' => $this->second->id, 'group' => 'registration', 'values' => ['open' => '1']]);

    expect(RegistrationSettings::on('open'))->toBeTrue();
    expect(RegistrationSettings::on('open', ($this->ctx)($this->src, $this->first)))->toBeFalse();
    expect(RegistrationSettings::on('open', ($this->ctx)($this->src, $this->second)))->toBeTrue();
    expect(RegistrationSettings::int('late_registration_days', ($this->ctx)($this->src, $this->second)))->toBe(9);

    $p = RegistrationSettings::period($this->src->id, $this->second->id);
    expect($p['from']['open'])->toContain($this->second->name)->and($p['from']['late_registration_days'])->toBe('2025/2026')
        ->and($p['from']['require_approval'])->toBe('University default');
});

it('saves a session\'s own settings from the settings page and drops them when nothing is set', function () {
    $this->actingAs($this->admin)->put(route('registrations.settings.update'), [
        'session' => $this->src->id, 'own' => ['open' => '1'], 'open' => '0',
        'windows' => [$this->s1->id => ['opens' => '2025-09-12T08:00', 'closes' => '2025-10-01T17:00']],
    ])->assertSessionHasNoErrors();

    expect(PeriodSetting::for($this->src->id, null)->first()->values)->toBe(['open' => '0']);
    expect($this->s1->fresh()->registration_opens_at->toDateTimeString())->toBe('2025-09-12 08:00:00');

    $this->actingAs($this->admin)->put(route('registrations.settings.update'), ['session' => $this->src->id])->assertSessionHasNoErrors();
    expect(PeriodSetting::count())->toBe(0);
    $this->actingAs($this->admin)->get(route('registrations.settings', ['session' => $this->src->id, 'semester' => $this->first->id]))->assertOk()->assertSee('Settings for');
});

it('copies registration settings session to session, saves the destination first and can be undone', function () {
    PeriodSetting::create(['academic_session_id' => $this->src->id, 'group' => 'registration', 'values' => ['open' => '0']]);
    PeriodSetting::create(['academic_session_id' => $this->dst->id, 'group' => 'registration', 'values' => ['open' => '1', 'fee_rule' => 'full']]);
    $svc = app(SessionCloneService::class);

    $op = $svc->run($this->src, $this->dst, ['registration'], $this->admin);

    expect(PeriodSetting::for($this->dst->id, null)->first()->values)->toBe(['open' => '0']);
    $saved = SetupSnapshot::find($op->meta['saved_first_as']);
    expect($saved->kind)->toBe('auto')->and($saved->academic_session_id)->toBe($this->dst->id)->and($saved->sections)->toBe(['registration']);
    expect($svc->preview($this->src, $this->dst, ['registration'])['total'])->toBe(0);

    $svc->undo($op, $this->admin);
    expect(PeriodSetting::for($this->dst->id, null)->first()->values)->toBe(['open' => '1', 'fee_rule' => 'full']);
});

it('copies one semester into another, remapping the semester and moving the window', function () {
    $item = BillItem::create(['code' => 'TUI', 'name' => 'Tuition']);
    Bill::create(['academic_session_id' => $this->src->id, 'semester_type_id' => $this->first->id, 'bill_item_id' => $item->id, 'amount' => 1000, 'is_active' => true]);
    Bill::create(['academic_session_id' => $this->src->id, 'bill_item_id' => $item->id, 'amount' => 5000, 'is_active' => true]);
    PeriodSetting::create(['academic_session_id' => $this->src->id, 'semester_type_id' => $this->first->id, 'group' => 'registration', 'values' => ['require_approval' => '0']]);
    $pair = new SemesterPair($this->first, $this->second);
    $svc = app(SessionCloneService::class);

    $preview = $svc->preview($this->src, $this->src, ['registration', 'windows', 'bills'], $pair);
    expect($preview['problems'])->toBe([])->and($preview['rows']['bills']['create'])->toBe(1);
    expect($svc->preview($this->src, $this->src, ['bills'], new SemesterPair($this->first, $this->first))['problems'])->not->toBeEmpty();

    $svc->run($this->src, $this->src, ['registration', 'windows', 'bills'], $this->admin, $pair);

    expect(PeriodSetting::for($this->src->id, $this->second->id)->first()->values)->toBe(['require_approval' => '0']);
    expect(Bill::where('academic_session_id', $this->src->id)->where('semester_type_id', $this->second->id)->first())
        ->not->toBeNull()->is_active->toBeFalse();
    // the first semester starts 2025-09-01 and the second 2026-02-01: the window (10 Sep) moves by those 153 days
    expect($this->s2->fresh()->registration_opens_at->toDateString())->toBe('2026-02-10');
    expect(array_keys(SessionCloneService::categoriesFor(true)))->not->toContain('assessments');
});

it('starts a new session from an existing one, with its semesters and setup', function () {
    PeriodSetting::create(['academic_session_id' => $this->src->id, 'group' => 'registration', 'values' => ['open' => '0']]);
    $svc = app(SessionCloneService::class);

    $preview = $svc->previewNew($this->src, '2027/2028', ['registration', 'windows']);
    expect($preview['problems'])->toBe([])->and($preview['semesters'])->toHaveCount(2)->and($preview['total'])->toBe(2);
    expect(AcademicSession::where('name', '2027/2028')->exists())->toBeFalse();
    expect($svc->previewNew($this->src, '2026/2027', [])['problems'][0])->toContain('already exists');

    ['session' => $new, 'batch' => $batch] = $svc->runNew($this->src, '2027/2028', ['registration', 'windows'], $this->admin);

    expect($new->status)->toBe('draft')->and($new->start_date->toDateString())->toBe('2027-09-01');
    expect($new->semesters()->count())->toBe(2);
    expect($new->semesters()->first()->registration_opens_at->toDateTimeString())->toBe('2027-09-10 08:00:00');
    expect(PeriodSetting::for($new->id, null)->first()->values)->toBe(['open' => '0']);
    expect($batch->meta)->not->toHaveKey('saved_first_as');
});

it('takes a snapshot and restores it, saving what was there first', function () {
    GradeBand::query()->delete();
    $a = GradeBand::create(['letter' => 'A', 'min_score' => 70, 'max_score' => 100, 'points' => 5, 'is_pass' => true]);
    GradeBand::create(['letter' => 'F', 'min_score' => 0, 'max_score' => 69, 'points' => 0, 'is_pass' => false]);
    Setting::put('registration.require_approval', '1');
    $snaps = app(SetupSnapshots::class);
    $snap = $snaps->take('Before the change', null, ['grading', 'registration', 'windows'], $this->admin);

    expect($snap->sections)->toBe(['registration', 'grading'])->and($snap->summary['grading'])->toBeGreaterThanOrEqual(2);

    // someone changes the grading and the registration rule
    $a->update(['min_score' => 75]);
    GradeBand::create(['letter' => 'B', 'min_score' => 60, 'max_score' => 74, 'points' => 4, 'is_pass' => true]);
    GradeBand::where('letter', 'F')->delete();
    Setting::put('registration.require_approval', '0');

    $plans = $snaps->preview($snap, ['grading', 'registration']);
    expect($plans['grading']->added)->toBe(1)->and($plans['grading']->updated)->toBe(1)->and($plans['grading']->removed)->toBe(1);
    expect(GradeBand::where('letter', 'B')->exists())->toBeTrue();   // preview changes nothing

    $result = $snaps->restore($snap, ['grading', 'registration'], $this->admin, 'Mistake');

    expect(GradeBand::orderBy('letter')->pluck('min_score', 'letter')->map(fn ($v) => (int) $v)->all())->toBe(['A' => 70, 'F' => 0]);
    expect(RegistrationSettings::on('require_approval'))->toBeTrue();
    expect($result['batch']->type)->toBe('snapshot_restore')->and($result['before']->kind)->toBe('auto');

    // and the restore itself can be undone by restoring what was saved first
    $snaps->restore($result['before'], ['grading'], $this->admin, 'Undo the restore');
    expect(GradeBand::where('letter', 'B')->exists())->toBeTrue();
});

it('serves the copy and snapshot pages and restores from the page', function () {
    $this->actingAs($this->admin)->get(route('sessions.clone', ['mode' => 'semester', 'source' => $this->src->id, 'dest' => $this->src->id,
        'source_type' => $this->first->id, 'dest_type' => $this->second->id, 'preview' => 1, 'categories' => ['windows']]))->assertOk()->assertSee('to copy');
    $this->actingAs($this->admin)->get(route('sessions.clone', ['mode' => 'new', 'source' => $this->src->id, 'preview' => 1]))->assertOk()->assertSee('2026/2027')->assertSee('already exists');

    $this->actingAs($this->admin)->post(route('sessions.clone.run'), ['mode' => 'new', 'source' => $this->src->id, 'new_name' => '2030/2031', 'categories' => ['windows']])
        ->assertSessionHas('success');
    expect(AcademicSession::where('name', '2030/2031')->exists())->toBeTrue();

    $this->actingAs($this->admin)->get(route('setup.snapshots.index'))->assertOk()->assertSee('Take a snapshot');
    $this->actingAs($this->admin)->post(route('setup.snapshots.store'), ['label' => 'Grading now', 'sections' => ['grading']])->assertRedirect();
    $snap = SetupSnapshot::where('label', 'Grading now')->firstOrFail();
    GradeBand::query()->delete();

    $this->actingAs($this->admin)->get(route('setup.snapshots.show', $snap))->assertOk()->assertSee('added back');
    $this->actingAs($this->admin)->post(route('setup.snapshots.restore', $snap), ['sections' => ['grading'], 'reason' => 'Deleted by mistake'])->assertSessionHas('success');
    expect(GradeBand::count())->toBe($snap->summary['grading'] - \App\Models\ResultKey::count() - \App\Models\DegreeClass::count());
    $this->actingAs($this->admin)->get(route('setup.snapshots.download', $snap))->assertOk()->assertHeader('content-type', 'application/json');
    expect(OperationBatch::where('type', 'snapshot_restore')->count())->toBe(1);

    $nobody = User::factory()->create();
    $this->actingAs($nobody)->get(route('setup.snapshots.index'))->assertForbidden();
});
