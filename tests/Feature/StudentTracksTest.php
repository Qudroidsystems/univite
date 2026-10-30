<?php

use App\Models\AcademicSession;
use App\Models\ComputedResult;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\StudentTrack;
use App\Models\TrackMigration;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    campusWorld($this);
    [$this->first, $this->second] = SemesterType::orderBy('sort_order')->take(2)->pluck('id')->all();
    $this->next = AcademicSession::create(['name' => '2027/2028']);
});

function result($test, Student $s, string $remark, ?string $action, bool $graduating = false): ComputedResult
{
    return ComputedResult::create(['student_id' => $s->id, 'academic_session_id' => $test->session->id, 'semester_type_id' => $test->second, 'level' => $s->current_level,
        'department_id' => $test->dept->id, 'programme_id' => $test->prog->id, 'sem_remark' => $remark, 'cum_remark' => 'GOOD', 'action' => $action, 'graduating' => $graduating]);
}

it('moves a class to the next semester, then up a level after the last semester, holding back who must stay', function () {
    $a = makeStudent($this, 'Ada', level: 100);
    $b = makeStudent($this, 'Bola', level: 100);
    $this->actingAs($this->admin);

    // first semester -> second semester of the same session: same level
    $this->post(route('tracks.store'), ['from_session_id' => $this->session->id, 'from_semester_type_id' => $this->first, 'to_session_id' => $this->session->id, 'to_semester_type_id' => $this->second, 'programme_id' => $this->prog->id])
        ->assertSessionHas('success');
    expect($a->fresh()->current_level)->toBe(100)->and($a->fresh()->current_semester_type_id)->toBe($this->second);

    // second semester -> next session's first semester: up a level, unless the result says repeat or withdraw
    result($this, $a, 'PASS', 'proceed');
    result($this, $b, 'REP', 'repeat');
    $c = makeStudent($this, 'Chi', level: 100, extra: ['current_semester_type_id' => $this->second]);
    result($this, $c, 'FAIL', 'withdraw');
    $query = ['preview' => 1, 'from_session_id' => $this->session->id, 'from_semester_type_id' => $this->second, 'to_session_id' => $this->next->id, 'to_semester_type_id' => $this->first, 'level' => 100];
    $this->get(route('tracks.index', $query))->assertOk()->assertSee('2 will move')->assertSee('Advised to withdraw');
    $this->post(route('tracks.store'), $query)->assertSessionHas('success');
    expect($a->fresh()->current_level)->toBe(200)->and($b->fresh()->current_level)->toBe(100)->and($c->fresh()->current_level)->toBe(100)
        ->and(StudentTrack::where('student_id', $a->id)->where('status', 'closed')->latest('id')->first()->semester_remark)->toBe('PASS');
    $this->get(route('students.show', $a->id))->assertSee('Semester by semester')->assertSee('2027/2028');

    // nobody is moved twice
    $this->post(route('tracks.store'), $query)->assertSessionHas('error');

    // undo puts them back
    $run = TrackMigration::latest('id')->first();
    $this->post(route('tracks.undo', $run))->assertSessionHas('success');
    expect($a->fresh()->current_level)->toBe(100)->and($a->fresh()->current_semester_type_id)->toBe($this->second)
        ->and(StudentTrack::where('student_id', $a->id)->where('status', 'active')->first()->academic_session_id)->toBe($this->session->id);
});

it('keeps final-level and graduating students where they are, and needs permission to move anyone', function () {
    $f = makeStudent($this, 'Femi', level: 400, extra: ['current_semester_type_id' => $this->second]);
    $plan = app(\App\Services\Students\StudentTrackService::class)->plan(\App\Data\Tracks\TrackScope::fromArray(['from_session_id' => $this->session->id, 'from_semester_type_id' => $this->second, 'to_session_id' => $this->next->id, 'to_semester_type_id' => $this->first]));
    expect($plan->firstWhere('studentId', $f->id)->held)->toBe('Already at the final level');

    $this->actingAs(makeStaff($this, 'Plain Staff'))->post(route('tracks.store'), ['from_session_id' => $this->session->id, 'from_semester_type_id' => $this->first, 'to_session_id' => $this->session->id, 'to_semester_type_id' => $this->second])->assertForbidden();
});
