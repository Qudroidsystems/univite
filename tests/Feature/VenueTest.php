<?php

use App\Models\Venue;
use App\Models\VenueIssue;
use App\Services\TimetableService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__ . '/../timetable-world.php';

beforeEach(fn () => timetableWorld($this));

it('registers a venue with a seat map that must hold its examination capacity', function () {
    $base = ['code' => 'ER1', 'name' => 'Education Hall', 'type' => 'exam_hall', 'capacity' => 200, 'teaching_capacity' => 0, 'exam_capacity' => 90,
        'seat_rows' => 8, 'seats_per_row' => 10, 'uses' => ['exam'], 'status' => 'available', 'blocked_seats' => 'R01-S01, r1-s2', 'accessible_seats' => 'R08-S10'];

    $this->actingAs($this->admin)->post(route('venues.store'), $base)->assertSessionHasErrors();
    expect(Venue::where('code', 'ER1')->exists())->toBeFalse();

    $this->actingAs($this->admin)->post(route('venues.store'), ['seat_rows' => 10] + $base)->assertRedirect();
    $v = Venue::where('code', 'ER1')->firstOrFail();
    expect($v->blocked_seats)->toBe(['R01-S01', 'R01-S02'])
        ->and(count($v->seatMap()))->toBe(90)
        ->and(collect($v->seatMap())->pluck('label'))->not->toContain('R01-S01');
});

it('blocks a venue during a closure or a blocking fault, and shows which published classes need moving', function () {
    $svc = app(TimetableService::class);
    $t = newTimetable($this, 'exam');
    $svc->generate($t, $this->admin);
    $svc->submit($t->fresh(), $this->admin);
    $svc->approve($t->fresh(), $this->admin);
    $svc->publish($t->fresh(), $this->admin);
    $hall = $this->venues->firstWhere('code', 'HALL');
    $used = $t->entries()->where('venue_id', $hall->id)->orderBy('date')->first();
    expect($used)->not->toBeNull();

    $this->actingAs($this->admin)->post(route('venues.closures.store', $hall), ['starts_on' => $used->date->toDateString(), 'ends_on' => $used->date->toDateString(), 'reason' => 'Roof repairs after the storm'])
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'affected'));
    expect($hall->fresh()->blockedReason($used->date))->toContain('Roof repairs');
    $this->actingAs($this->admin)->get(route('venues.show', $hall))->assertOk()->assertSee('Roof repairs')->assertSee($used->course->code);

    $lab = $this->venues->firstWhere('code', 'LAB1');
    $this->actingAs($this->admin)->post(route('venues.issues.store', $lab), ['title' => 'No power', 'severity' => 'blocking'])->assertRedirect();
    expect($lab->fresh()->blockedReason(today()))->toContain('No power');
    $issue = VenueIssue::where('venue_id', $lab->id)->first();
    $this->actingAs($this->admin)->post(route('venues.issues.resolve', [$lab, $issue]), ['status' => 'resolved', 'resolution' => 'Generator fixed'])->assertRedirect();
    expect($lab->fresh()->blockedReason(today()))->toBeNull();
});

it('refuses a booking that clashes with another booking', function () {
    $cr = $this->venues->firstWhere('code', 'CR1');
    $date = today()->addDays(3)->toDateString();
    $this->actingAs($this->admin)->post(route('venues.bookings.store', $cr), ['title' => 'Senate committee', 'date' => $date, 'starts_at' => '10:00', 'ends_at' => '12:00'])->assertSessionHas('success');
    $this->actingAs($this->admin)->post(route('venues.bookings.store', $cr), ['title' => 'Seminar', 'date' => $date, 'starts_at' => '11:00', 'ends_at' => '13:00'])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Senate committee'));
    $this->actingAs($this->admin)->post(route('venues.bookings.store', $cr), ['title' => 'Seminar', 'date' => $date, 'starts_at' => '12:00', 'ends_at' => '13:00'])->assertSessionHas('success');
});

it('opens the venue, timetable and availability pages', function () {
    $svc = app(TimetableService::class);
    $t = newTimetable($this, 'lecture');
    $svc->generate($t, $this->admin);
    $unit = $t->entries()->value('unit_key');
    $this->actingAs($this->admin);
    foreach ([
        route('venues.index'), route('venues.create'), route('venues.edit', $this->venues[0]), route('venues.usage'),
        route('venues.availability', ['date' => today()->addDay()->toDateString(), 'from' => '08:00', 'to' => '10:00', 'seats' => 30]),
        route('timetables.index'), route('timetables.create'), route('timetables.show', $t), route('timetables.settings', $t), route('timetables.items', $t),
        route('timetables.entry', [$t, $unit]), route('timetables.whatif', [$t, 'run' => 1]), route('timetables.invigilators', $t), route('timetables.print', $t),
        route('staff-availability.index'),
    ] as $url) {
        $this->get($url)->assertOk();
    }
    // A student sees the published lecture timetable for their courses.
    $svc->submit($t->fresh(), $this->admin);
    $svc->approve($t->fresh(), $this->admin);
    $svc->publish($t->fresh(), $this->admin);
    $student = \App\Models\Student::where('last_name', 'CSC100')->first()->user;
    $this->actingAs($student)->get(route('my.timetable'))->assertOk()->assertSee('CSC 101');
});

it('keeps a venue\'s map position for the app, both numbers or neither', function () {
    $base = ['code' => 'GPS1', 'name' => 'Science Hall', 'type' => 'lecture_hall', 'capacity' => 100, 'teaching_capacity' => 100, 'exam_capacity' => 50, 'uses' => ['lecture'], 'status' => 'available'];
    $this->actingAs($this->admin)->post(route('venues.store'), ['latitude' => '7.8231450'] + $base)->assertSessionHasErrors('longitude');
    $this->actingAs($this->admin)->post(route('venues.store'), ['latitude' => '7.8231450', 'longitude' => '6.0687210'] + $base)->assertRedirect();
    $v = Venue::where('code', 'GPS1')->firstOrFail();
    expect($v->location())->toMatchArray(['code' => 'GPS1', 'lat' => 7.823145, 'lng' => 6.068721]);
    $this->actingAs($this->admin)->get(route('venues.show', $v))->assertOk()->assertSee('7.823145');
});
