<?php

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\RegistrationForm;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\Timetable;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueClosure;
use App\Services\TimetableService;
use App\Timetabling\Checker;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__ . '/../timetable-world.php';

beforeEach(fn () => timetableWorld($this));

it('builds the items from registrations and the curriculum', function () {
    $t = newTimetable($this, 'lecture');
    expect($t->items()->count())->toBe(16)
        ->and($t->items()->where('course_id', $this->courses['CSC 101']->id)->value('students'))->toBe(60)
        ->and($t->items()->where('course_id', $this->courses['MTH 201']->id)->value('students'))->toBe(30);
});

it('generates a lecture timetable that keeps every hard rule', function () {
    $t = newTimetable($this, 'lecture');
    $summary = app(TimetableService::class)->generate($t, $this->admin);

    expect($summary['unplaced'])->toBe(0)->and($summary['violations'])->toBe(0)->and($summary['placed'])->toBe(16);

    // An independent check: nothing for the same cohort at the same time, CSC 101 (60) never in a 40-seat room.
    $entries = $t->entries()->with('course', 'venue')->get();
    foreach ($entries->groupBy(fn ($e) => $e->slots[0]) as $rows) {
        expect($rows->pluck('venue_id')->duplicates())->toBeEmpty();
        $codes = $rows->map(fn ($e) => $e->course->code);
        expect($codes->filter(fn ($c) => str_starts_with($c, 'CSC 1'))->count())->toBeLessThanOrEqual(1);
        expect($codes->contains('CSC 101') && $codes->contains(fn ($c) => str_starts_with($c, 'MTH 1')))->toBeFalse();
    }
    expect($entries->firstWhere('course.code', 'CSC 101')->venue->code)->toBe('LT1');
});

it('explains a class it cannot place and suggests what to do', function () {
    $t = newTimetable($this, 'lecture');
    $t->items()->where('course_id', $this->courses['CSC 101']->id)->update(['students' => 200]);
    $summary = app(TimetableService::class)->generate($t, $this->admin);

    expect($summary['unplaced'])->toBe(1)->and($summary['violations'])->toBe(0);
    $issues = $t->items()->where('course_id', $this->courses['CSC 101']->id)->first()->issues[0];
    expect(implode(' ', $issues['reasons']))->toContain('largest suitable venue (Lecture Theatre 1) holds 120')
        ->and(implode(' ', $issues['suggestions']))->toContain('Split the class into groups');
});

it('places practicals only in a venue with the facilities they need', function () {
    $t = newTimetable($this, 'lecture');
    $t->items()->create(['course_id' => $this->courses['CSC 201']->id, 'activity' => 'practical', 'students' => 30, 'meetings' => 1, 'duration' => 2, 'facilities' => ['computers']]);
    app(TimetableService::class)->generate($t, $this->admin);

    $practical = $t->entries()->whereHas('item', fn ($q) => $q->where('activity', 'practical'))->with('venue')->get();
    expect($practical)->toHaveCount(1)->and($practical[0]->venue->code)->toBe('LAB1')->and(count($practical[0]->slots))->toBe(2);
});

it('generates an examination timetable, shares the hall, and staffs it with invigilators', function () {
    $staff = collect(range(1, 8))->map(fn ($i) => User::create(['name' => "Invigilator {$i}", 'email' => "inv{$i}@example.com", 'password' => 'secret-pass']));
    $t = newTimetable($this, 'exam');
    $t->update(['config' => array_merge($t->config, ['invigilators' => ['role' => null, 'users' => $staff->pluck('id')->all()], 'min_invigilators' => 1])]);
    $summary = app(TimetableService::class)->generate($t, $this->admin);

    expect($summary['unplaced'])->toBe(0)->and($summary['violations'])->toBe(0)
        ->and($summary['invigilation']['short'])->toBe([]);
    // No student writes two papers in one sitting
    $byStudent = [];
    foreach ($t->entries()->get() as $e) {
        foreach (CourseRegistration::where('course_id', $e->course_id)->pluck('student_id') as $sid) {
            $byStudent[$sid][$e->slots[0]][$e->course_id] = true;
        }
    }
    foreach ($byStudent as $slots) {
        foreach ($slots as $courses) {
            expect(count($courses))->toBe(1);
        }
    }
    expect($t->invigilators()->count())->toBeGreaterThan(0);
});

it('refuses a manual move that breaks a hard rule, and accepts a safe one', function () {
    $svc = app(TimetableService::class);
    $t = newTimetable($this, 'lecture');
    $svc->generate($t, $this->admin);
    $a = $t->entries()->whereHas('course', fn ($q) => $q->where('code', 'CSC 102'))->first();
    $b = $t->entries()->whereHas('course', fn ($q) => $q->where('code', 'CSC 103'))->first();

    expect(fn () => $svc->move($t, $a->unit_key, $b->slots, [$this->venues[1]->id => 30], $this->admin))
        ->toThrow(RuntimeException::class, 'take both');

    $alt = $svc->alternatives($t, $a->unit_key)[0];
    $svc->move($t->fresh(), $a->unit_key, $alt['slots'], $alt['venues'], $this->admin, 'Lecturer asked');
    $moved = $t->entries()->where('unit_key', $a->unit_key)->first();
    expect($moved->locked)->toBeTrue()->and($moved->slots)->toBe($alt['slots'])
        ->and($t->fresh()->summary['violations'])->toBe(0);
});

it('flags a timetable that a venue closure breaks and offers replacement rooms', function () {
    $svc = app(TimetableService::class);
    $t = newTimetable($this, 'exam');
    $svc->generate($t, $this->admin);
    $used = $t->entries()->whereNotNull('venue_id')->first();
    VenueClosure::create(['venue_id' => $used->venue_id, 'starts_on' => $used->date, 'ends_on' => $used->date, 'reason' => 'Roof repairs']);

    $check = $svc->check($t->fresh());
    expect(collect($check['violations'])->pluck('message')->implode(' '))->toContain('Roof repairs');
    $impact = $svc->venueImpact($used->venue, $used->date, $used->date);
    expect($impact)->not->toBeEmpty();
});

it('runs a what-if without changing the saved timetable', function () {
    $svc = app(TimetableService::class);
    $t = newTimetable($this, 'exam');
    $svc->generate($t, $this->admin);
    $before = $t->entries()->count();
    $r = $svc->whatIf($t->fresh(), ['close_venues' => [$this->venues[3]->id]]);
    expect($r['after']['placed'] + $r['after']['unplaced'])->toBe(16)->and($t->entries()->count())->toBe($before);
});

it('moves through review, approval and publication, and a revision replaces the published version', function () {
    $svc = app(TimetableService::class);
    $t = newTimetable($this, 'exam');
    $svc->generate($t, $this->admin);
    $svc->submit($t->fresh(), $this->admin);
    $svc->approve($t->fresh(), $this->admin, 'Checked by the committee');
    $svc->publish($t->fresh(), $this->admin);
    expect($t->fresh()->status)->toBe('published');

    $v2 = $svc->revise($t->fresh(), $this->admin);
    expect($v2->version)->toBe(2)->and($v2->entries()->count())->toBe($t->entries()->count());
    $svc->generate($v2, $this->admin);
    $svc->submit($v2->fresh(), $this->admin);
    $svc->approve($v2->fresh(), $this->admin);
    $svc->publish($v2->fresh(), $this->admin);
    expect($t->fresh()->status)->toBe('superseded')->and($v2->fresh()->status)->toBe('published');
    expect(\App\Models\OperationBatch::where('type', 'timetable_publish')->count())->toBe(2);
});
