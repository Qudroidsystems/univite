<?php

use App\Models\ClearanceItem;
use App\Models\ClinicVisit;
use App\Models\HealthAccessLog;
use App\Models\HealthRecord;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 09:00:00');
    campusWorld($this);
    $this->ada = makeStudent($this, 'Ada');
    $this->nurse = makeStaff($this, 'Clinic Nurse', null, ['View health records', 'Record clinic visits', 'Record medical examinations']);
    $this->registrar = makeStaff($this, 'Records Officer', null, ['View clinic statistics']);
});

afterEach(fn () => Carbon::setTestNow());

it('keeps clinical notes encrypted, logs who opened a record, and shows the person that log', function () {
    $this->actingAs($this->ada->user)->put(route('my.health.update'), ['blood_group' => 'O+', 'genotype' => 'AS', 'allergies' => 'Penicillin', 'emergency_name' => 'Mrs Student', 'emergency_phone' => '08030000000'])
        ->assertSessionHas('success');

    $this->actingAs($this->nurse)->get(route('clinic.index', ['patient' => $this->ada->matric_number]))->assertRedirect(route('clinic.patient', $this->ada->user));
    $this->get(route('clinic.patient', $this->ada->user))->assertOk()->assertSee('Penicillin')->assertSee('O+ / AS');
    $this->post(route('clinic.visits.store', $this->ada->user), ['category' => 'illness', 'complaint' => 'Fever and headache', 'diagnosis' => 'Malaria', 'treatment' => 'ACT', 'outcome' => 'treated', 'rest_days' => 2])
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'excuse note'));

    $raw = DB::table('clinic_visits')->first();
    expect($raw->diagnosis)->not->toContain('Malaria')->and(ClinicVisit::first()->diagnosis)->toBe('Malaria')
        ->and(DB::table('health_records')->value('allergies'))->not->toContain('Penicillin');

    $visit = ClinicVisit::first();
    expect($visit->excused_from->toDateString())->toBe('2026-11-02')->and($visit->excused_to->toDateString())->toBe('2026-11-03');
    $this->get(route('clinic.excuse', $visit))->assertOk()->assertSee('Monday 2 November 2026')->assertDontSee('Malaria');

    // The student sees who looked, and their own excuse note; a lecturer can check the note without the reason.
    expect(HealthAccessLog::where('user_id', $this->ada->user_id)->pluck('action')->all())->toBe(['viewed', 'visit_recorded']);
    $this->actingAs($this->ada->user)->get(route('my.health'))->assertOk()->assertSee('Clinic Nurse')->assertSee('Excuse note (2 days)');
    $this->get(route('my.health.excuse', $visit))->assertOk();
    auth()->logout();
    $this->get(route('excuse.check', $visit->excuse_token))->assertOk()->assertSee('GENUINE')->assertSee('2 November 2026')->assertDontSee('Malaria');

    // Nobody else gets in.
    $this->actingAs($this->registrar)->get(route('clinic.patient', $this->ada->user))->assertForbidden();
    $other = makeStudent($this, 'Bayo', 'male');
    $this->actingAs($other->user)->get(route('my.health.excuse', $visit))->assertNotFound();
});

it('clears the medical examination step for a new student once examined', function () {
    ClearanceItem::create(['name' => 'Medical examination', 'code' => 'MED', 'mode' => 'auto', 'auto_rule' => 'medical_examined', 'is_required' => true, 'is_active' => true, 'sort_order' => 1]);
    $this->ada->update(['needs_onboarding' => true]);
    app(\App\Services\ClearanceService::class)->refresh($this->ada->fresh());
    $row = fn () => $this->ada->fresh()->clearances()->whereHas('item', fn ($q) => $q->where('auto_rule', 'medical_examined'))->first();
    expect($row()->status)->not->toBe('cleared');

    $this->actingAs($this->nurse)->post(route('clinic.examine', $this->ada->user), ['fitness' => 'fit_with_conditions'])->assertSessionHasErrors('fitness_note');
    $this->post(route('clinic.examine', $this->ada->user), ['fitness' => 'fit', 'height_cm' => 170, 'weight_kg' => 65, 'genotype' => 'AA'])->assertSessionHas('success');
    expect($row()->status)->toBe('cleared')->and(HealthRecord::sole()->bmi())->toBe(22.5);
});

it('reports counts without names', function () {
    $this->actingAs($this->nurse)->post(route('clinic.visits.store', $this->ada->user), ['category' => 'injury', 'outcome' => 'referred', 'referred_to' => 'General Hospital']);
    $this->post(route('clinic.visits.store', $this->nurse), ['category' => 'check_up', 'outcome' => 'treated']);
    $this->actingAs($this->registrar)->get(route('clinic.stats'))->assertOk()->assertDontSee('Student Ada')
        ->assertSeeInOrder(['Visits', '2', 'People seen', '2', 'Students', '1', 'Staff', '1']);
});
