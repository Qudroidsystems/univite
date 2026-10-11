<?php

use App\Models\CourseRegistration;
use App\Models\ExamSeat;
use App\Models\RegistrationForm;
use App\Models\Student;
use App\Models\User;
use App\Services\SeatingService;
use App\Services\TimetableService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__ . '/../timetable-world.php';

beforeEach(function () {
    timetableWorld($this);
    $this->t = newTimetable($this, 'exam');
    $svc = app(TimetableService::class);
    $svc->generate($this->t, $this->admin);
    $svc->submit($this->t->fresh(), $this->admin);
    $svc->approve($this->t->fresh(), $this->admin);
    $this->t = $this->t->fresh();
});

it('refuses to seat candidates before the examination timetable is approved', function () {
    $draft = newTimetable($this, 'exam', ['name' => 'Resit examinations']);
    expect(fn () => app(SeatingService::class)->generate($draft, [], $this->admin))->toThrow(RuntimeException::class, 'Approve the examination timetable');
});

it('gives every candidate one seat per paper, never two candidates in one seat, and reports what it could not separate', function () {
    $seating = app(SeatingService::class);
    $plan = $seating->generate($this->t, ['pattern' => 'side'], $this->admin);
    $seats = $plan->seats()->with('venue')->get();

    // Everyone registered for every paper has exactly one seat for it.
    $expected = CourseRegistration::whereIn('course_id', $this->t->entries()->pluck('course_id'))->count();
    expect($seats)->toHaveCount($expected)
        ->and($plan->summary['seated'])->toBe($expected)
        ->and($plan->summary['unseated'])->toBe(0);
    // One candidate per seat per sitting, and only seats that exist on the hall's map.
    expect($seats->map(fn ($s) => $s->slot . '|' . $s->venue_id . '|' . $s->seat_label)->duplicates())->toBeEmpty();
    foreach ($seats->groupBy('venue_id') as $rows) {
        $labels = collect($rows->first()->venue->seatMap())->pluck('label');
        expect($rows->pluck('seat_label')->diff($labels))->toBeEmpty();
    }
    // The recorded count of same-paper neighbours matches an independent recount, and any it could not avoid is explained.
    expect($seating->validate($plan))->toBe($plan->summary['adjacent']);
    if ($plan->summary['adjacent'] > 0) {
        expect(implode(' ', $plan->summary['problems']))->toContain('sit next to each other');
    }
});

it('keeps candidates of the same paper apart in a shared hall', function () {
    $plan = app(SeatingService::class)->generate($this->t, ['pattern' => 'side'], $this->admin);
    $hall = $this->venues->firstWhere('code', 'HALL');
    $shared = $plan->seats()->where('venue_id', $hall->id)->get()->groupBy('slot')->filter(fn ($rows) => $rows->pluck('course_id')->unique()->count() > 1);
    expect($shared)->not->toBeEmpty();
    foreach ($shared as $rows) {
        $placed = $rows->mapWithKeys(fn ($s) => [$s->seat_row . '-' . $s->seat_col => $s->course_id]);
        foreach ($rows as $s) {
            // Side by side in the same row: never the same paper while there is room to separate them.
            expect($placed[$s->seat_row . '-' . ($s->seat_col + 1)] ?? null)->not->toBe($s->course_id);
        }
    }
});

it('moves a candidate only to a free seat, and warns before seating them next to the same paper', function () {
    $seating = app(SeatingService::class);
    $plan = $seating->generate($this->t, [], $this->admin);
    $hall = $this->venues->firstWhere('code', 'HALL');
    $rows = $plan->seats()->where('venue_id', $hall->id)->get()->groupBy('slot')->first();
    [$a, $b] = [$rows[0], $rows[1]];

    expect(fn () => $seating->move($a, $hall->id, $b->seat_label, 'Test', $this->admin))->toThrow(RuntimeException::class, 'already taken');

    $free = collect($hall->seatMap())->pluck('label')->diff($rows->pluck('seat_label'))->first();
    if ($free) {
        $moved = $seating->move($a, $hall->id, $free, 'Candidate needs to sit near the door', $this->admin, true);
        expect($moved->seat_label)->toBe($free)->and($moved->moved_from)->not->toBeNull();
    }
});

it('seats a late registrant without disturbing anyone else', function () {
    $seating = app(SeatingService::class);
    $plan = $seating->generate($this->t, [], $this->admin);
    $before = $plan->seats()->pluck('seat_label', 'id');

    // A candidate registers after the plan was made: their paper has no seat for them yet.
    $late = $plan->seats()->latest('id')->first();
    $late->delete();
    $before->forget($late->id);
    expect($seating->drift($plan)['missing'])->toHaveCount(1);

    expect($seating->seatLate($plan, $this->admin))->toBe(1);
    expect($seating->drift($plan)['missing'])->toBeEmpty()
        ->and($plan->seats()->whereIn('id', $before->keys())->pluck('seat_label', 'id')->all())->toBe($before->all());
});

it('publishes seat numbers that students see on their portal and their slip', function () {
    $seating = app(SeatingService::class);
    $plan = $seating->generate($this->t, [], $this->admin);
    $seating->approve($plan, $this->admin);
    expect(fn () => $seating->publish($plan->fresh(), $this->admin))->toThrow(RuntimeException::class, 'Publish the examination timetable first');

    app(TimetableService::class)->publish($this->t->fresh(), $this->admin);
    $told = $seating->publish($plan->fresh(), $this->admin);
    expect($told)->toBeGreaterThan(100);

    $seat = ExamSeat::where('seating_plan_id', $plan->id)->with('student.user', 'course')->first();
    $this->actingAs($seat->student->user)->get(route('my.exams'))->assertOk()->assertSee($seat->seat_number)->assertSee($seat->course->code);
    $this->actingAs($seat->student->user)->get(route('my.exams.slip'))->assertOk()->assertSee($seat->code);
    expect($seat->student->user->notifications()->where('data->title', 'Your examination seat numbers')->exists())->toBeTrue();

    // The invigilator's check: the code on the slip finds the candidate and marks them present.
    $this->actingAs($this->admin)->get(route('seating.verify', $seat->code))->assertOk()->assertSee($seat->student->matric_number);
    $this->actingAs($this->admin)->post(route('seating.verify.mark', $seat->code), ['mark' => 'present'])->assertRedirect();
    expect($seat->fresh()->attendance)->toBe('present');
});

it('shows the seating pages to the examinations office', function () {
    $plan = app(SeatingService::class)->generate($this->t, [], $this->admin);
    $first = $plan->seats()->first();
    $this->actingAs($this->admin)->get(route('seating.show', $this->t))->assertOk()->assertSee('Seating');
    $this->actingAs($this->admin)->get(route('seating.hall', [$this->t, $first->slot, $first->venue_id]))->assertOk()->assertSee($first->seat_label);
});
