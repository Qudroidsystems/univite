<?php

use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $f = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $this->csc = Department::create(['faculty_id' => $f->id, 'name' => 'Computer Science', 'code' => 'CSC']);
    $this->mth = Department::create(['faculty_id' => $f->id, 'name' => 'Mathematics', 'code' => 'MTH']);
    $this->bscCsc = Programme::create(['department_id' => $this->csc->id, 'name' => 'B.Sc. Computer Science', 'code' => 'BSC-CSC', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
    $this->bscMth = Programme::create(['department_id' => $this->mth->id, 'name' => 'B.Sc. Mathematics', 'code' => 'BSC-MTH', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
    $this->actingAs($this->admin);
});

function addToCurriculum($test, Programme $p, Course $c, ?string $status = null)
{
    return $test->post(route('academics.curriculum.store'), ['programme_id' => $p->id, 'course_ids' => [$c->id], 'level' => 100, 'semester_order' => 1, 'status' => $status]);
}

it('lets the owning department use its course as any type, and others only as the course is shared', function () {
    $private = Course::create(['department_id' => $this->csc->id, 'code' => 'CSC 101', 'title' => 'Intro to Computing', 'credit_units' => 3]);
    $elective = Course::create(['department_id' => $this->csc->id, 'code' => 'CSC 105', 'title' => 'Computing Ethics', 'credit_units' => 2, 'shared_as' => 'elective']);
    $either = Course::create(['department_id' => $this->csc->id, 'code' => 'CSC 107', 'title' => 'Python', 'credit_units' => 2, 'shared_as' => 'either']);
    $general = Course::create(['department_id' => $this->csc->id, 'code' => 'GST 101', 'title' => 'Use of English', 'credit_units' => 2, 'shared_as' => 'general']);

    // the owning department: any type
    addToCurriculum($this, $this->bscCsc, $private, 'elective')->assertSessionHasNoErrors()->assertSessionMissing('error');
    expect(ProgrammeCourse::where('programme_id', $this->bscCsc->id)->value('status'))->toBe('elective');

    // another department: not shared at all, or not as asked
    addToCurriculum($this, $this->bscMth, $private)->assertSessionHas('error', fn ($m) => str_contains($m, 'has not opened it to other departments'));
    addToCurriculum($this, $this->bscMth, $elective, 'compulsory')->assertSessionHas('error', fn ($m) => str_contains($m, 'only as elective'));
    expect(ProgrammeCourse::where('programme_id', $this->bscMth->id)->count())->toBe(0);

    // as shared: no type chosen takes the shared type; "either" defaults to elective and allows a compulsory elective
    addToCurriculum($this, $this->bscMth, $elective)->assertSessionMissing('error');
    addToCurriculum($this, $this->bscMth, $either, 'compulsory_elective')->assertSessionMissing('error');
    addToCurriculum($this, $this->bscMth, $general, 'compulsory')->assertSessionMissing('error');
    expect(ProgrammeCourse::where('programme_id', $this->bscMth->id)->pluck('status', 'course_id')->all())
        ->toBe([$elective->id => 'elective', $either->id => 'compulsory_elective', $general->id => 'compulsory']);

    // changing the type later is held to the same rule
    $entry = ProgrammeCourse::where('programme_id', $this->bscMth->id)->where('course_id', $either->id)->first();
    $this->put(route('academics.curriculum.update', $entry), ['level' => 100, 'semester_order' => 1, 'status' => 'compulsory'])->assertSessionHasErrors('status');
    $this->put(route('academics.curriculum.update', $entry), ['level' => 100, 'semester_order' => 1, 'status' => 'elective'])->assertSessionHasNoErrors();

    // the curriculum page lists this department's courses and the courses others share, not private ones
    Course::create(['department_id' => $this->csc->id, 'code' => 'CSC 109', 'title' => 'Web Design', 'credit_units' => 2, 'shared_as' => 'either']);
    $this->get(route('academics.curriculum.index', ['programme' => $this->bscMth->id]))->assertOk()
        ->assertSee('Computer Science — shared with other departments')->assertSee('CSC 109 — Web Design (2u, as E or CE)')->assertDontSee('CSC 101 — Intro to Computing');
});

it('will not stop sharing a course another department already uses', function () {
    $c = Course::create(['department_id' => $this->csc->id, 'code' => 'CSC 107', 'title' => 'Python', 'credit_units' => 2, 'shared_as' => 'either']);
    addToCurriculum($this, $this->bscMth, $c, 'compulsory_elective')->assertSessionMissing('error');
    $base = ['department_id' => $this->csc->id, 'code' => 'CSC 107', 'title' => 'Python', 'credit_units' => 2, 'course_type' => 'compulsory'];

    $this->put(route('academics.courses.update', $c), $base + ['shared_as' => ''])->assertSessionHasErrors('shared_as');
    $this->put(route('academics.courses.update', $c), $base + ['shared_as' => 'elective'])->assertSessionHasErrors('shared_as');
    $this->put(route('academics.courses.update', $c), $base + ['shared_as' => 'compulsory_elective'])->assertSessionHasNoErrors();
    expect($c->fresh()->shared_as)->toBe('compulsory_elective');

    // the course list shows how it is shared
    $this->get(route('academics.courses.index'))->assertOk()->assertSee('Shared · CE')->assertSee('Other departments');
});

it('picks prerequisites from the list of courses, shows them, and refuses a course needing itself or a circle', function () {
    $a = Course::create(['department_id' => $this->csc->id, 'code' => 'CSC 101', 'title' => 'Intro', 'credit_units' => 3]);
    $b = Course::create(['department_id' => $this->mth->id, 'code' => 'MTH 101', 'title' => 'Algebra', 'credit_units' => 3]);
    $c = Course::create(['department_id' => $this->csc->id, 'code' => 'CSC 201', 'title' => 'Data Structures', 'credit_units' => 3]);
    $base = ['department_id' => $this->csc->id, 'code' => 'CSC 201', 'title' => 'Data Structures', 'credit_units' => 3];

    $this->get(route('academics.courses.index'))->assertOk()->assertSee('MTH 101 — Algebra (3u)')->assertSee('data-chosen-for="prerequisites"', false);

    $this->put(route('academics.courses.update', $c), $base + ['prerequisites' => [$a->id, $b->id]])->assertSessionHasNoErrors();
    expect($c->fresh()->prerequisites->pluck('code')->sort()->values()->all())->toBe(['CSC 101', 'MTH 101']);
    $this->get(route('academics.courses.index'))->assertSee('title="Algebra">MTH 101', false);

    $this->put(route('academics.courses.update', $c), $base + ['prerequisites' => [$c->id]])->assertSessionHasErrors('prerequisites');
    // CSC 101 needing CSC 201 would close a circle (CSC 201 already needs CSC 101)
    $this->put(route('academics.courses.update', $a), ['department_id' => $this->csc->id, 'code' => 'CSC 101', 'title' => 'Intro', 'credit_units' => 3, 'prerequisites' => [$c->id]])
        ->assertSessionHasErrors(['prerequisites' => 'That would go round in a circle: one of the chosen courses already needs CSC 101 (directly or through another course).']);

    // clearing them
    $this->put(route('academics.courses.update', $c), $base + ['prerequisites' => []])->assertSessionHasNoErrors();
    expect($c->fresh()->prerequisites)->toHaveCount(0);
});

it('finds courses by faculty, semester, session, units, sharing, prerequisites, curriculum use and status', function () {
    $s1 = \App\Models\AcademicSession::create(['name' => '2025/2026']);
    $s2 = \App\Models\AcademicSession::create(['name' => '2026/2027']);
    $sem = \App\Models\SemesterType::orderBy('sort_order')->first();
    $a = Course::create(['department_id' => $this->csc->id, 'code' => 'CSC 101', 'title' => 'Intro', 'credit_units' => 3, 'semester_type_id' => $sem->id, 'academic_session_id' => $s1->id]);
    $b = Course::create(['department_id' => $this->mth->id, 'code' => 'MTH 101', 'title' => 'Algebra', 'credit_units' => 2, 'academic_session_id' => $s2->id, 'shared_as' => 'elective', 'is_active' => false]);
    $b->prerequisites()->attach($a->id);
    ProgrammeCourse::create(['programme_id' => $this->bscCsc->id, 'course_id' => $a->id, 'level' => 100, 'semester_order' => 1]);

    $see = fn (array $q, array $yes, array $no) => tap($this->get(route('academics.courses.index', $q))->assertOk(), function ($r) use ($yes, $no) {
        foreach ($yes as $t) { $r->assertSee('<span class="fw-semibold">' . $t . '</span>', false); }
        foreach ($no as $t) { $r->assertDontSee('<span class="fw-semibold">' . $t . '</span>', false); }
    });
    $see(['academic_session_id' => $s1->id], ['Intro'], ['Algebra']);
    $see(['semester_type_id' => $sem->id], ['Intro'], ['Algebra']);
    $see(['credit_units' => 2], ['Algebra'], ['Intro']);
    $see(['shared_as' => 'own'], ['Intro'], ['Algebra']);
    $see(['shared_as' => 'elective'], ['Algebra'], ['Intro']);
    $see(['prerequisite' => 'with'], ['Algebra'], ['Intro']);
    $see(['curriculum' => 'off'], ['Algebra'], ['Intro']);
    $see(['is_active' => '0'], ['Algebra'], ['Intro']);
    $see(['faculty' => $this->csc->faculty_id, 'q' => 'alg'], ['Algebra'], ['Intro']);
    $this->get(route('academics.courses.index'))->assertSee('All sessions')->assertSee('More filters');
});
