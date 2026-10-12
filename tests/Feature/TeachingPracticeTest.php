<?php

use App\Models\CourseResult;
use App\Models\RegistrationForm;
use App\Models\SemesterType;
use App\Models\TpExercise;
use App\Models\TpPosting;
use App\Models\TpSchool;
use App\Models\Course;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 09:00:00');
    campusWorld($this);
    $this->coordinator = makeStaff($this, 'TP Coordinator', null, ['View teaching practice', 'Set up teaching practice', 'Post students for teaching practice', 'Record teaching practice scores', 'Publish teaching practice scores']);
    $this->supervisor = makeStaff($this, 'Dr Supervisor');
    $this->type = SemesterType::orderBy('sort_order')->first();
    $this->course = Course::create(['department_id' => $this->dept->id, 'code' => 'EDU 411', 'title' => 'Teaching Practice', 'credit_units' => 6, 'level' => 400]);
    $this->ada = makeStudent($this, 'Ada', 'female', 400);
    $this->bayo = makeStudent($this, 'Bayo', 'male', 400);
    $this->junior = makeStudent($this, 'Junior', 'male', 200);
    $this->school1 = TpSchool::create(['name' => 'Ikere Grammar School', 'town' => 'Ikere', 'default_capacity' => 5]);
    $this->school2 = TpSchool::create(['name' => 'Ado Comprehensive', 'town' => 'Ado', 'default_capacity' => 5]);
});

afterEach(fn () => Carbon::setTestNow());

function tpExercise($test): TpExercise
{
    $criteria = collect(TpExercise::DEFAULT_CRITERIA)->map(fn ($c) => ['label' => $c['label'], 'max' => $c['max']])->all();
    $test->actingAs($test->coordinator)->post(route('tp.exercises.store'), [
        'name' => 'Teaching Practice 2026/2027', 'academic_session_id' => $test->session->id, 'semester_type_id' => $test->type->id, 'course_id' => $test->course->id,
        'starts_on' => '2026-11-09', 'ends_on' => '2026-12-18', 'visits_required' => 2, 'criteria' => $criteria, 'choices_open' => 1, 'max_choices' => 2,
        'audience' => ['who' => 'students', 'levels' => [400]],
    ])->assertRedirect();

    return TpExercise::latest('id')->first();
}

it('posts students by their choices within each school\'s places, and supervisors mark their visits', function () {
    $ex = tpExercise($this);
    $this->post(route('tp.exercises.schools', $ex), ['capacity' => [$this->school1->id => 1, $this->school2->id => 3]])->assertSessionHas('success');
    $this->post(route('tp.exercises.enrol', $ex))->assertSessionHas('success', '2 student(s) added to the exercise.');

    // Both want Ikere, which has one place; Ada chose first.
    foreach ([$this->ada, $this->bayo] as $s) {
        $p = TpPosting::where('student_id', $s->id)->sole();
        $this->actingAs($s->user)->post(route('my.tp.choose', $p), ['choices' => [$this->school1->id, $this->school2->id], 'subject' => 'Chemistry'])->assertSessionHas('success');
    }
    $this->actingAs($this->coordinator)->post(route('tp.exercises.auto-post', $ex))->assertSessionHas('success', '2 student(s) posted.');
    $ada = TpPosting::where('student_id', $this->ada->id)->sole();
    $bayo = TpPosting::where('student_id', $this->bayo->id)->sole();
    expect($ada->tp_school_id)->toBe($this->school1->id)->and($bayo->tp_school_id)->toBe($this->school2->id);

    // Moving Bayo into the full school is refused.
    $this->post(route('tp.postings.post', $bayo), ['school_id' => $this->school1->id])->assertSessionHas('error', fn ($m) => str_contains($m, 'no places left'));
    $this->get(route('tp.letter', $ada))->assertOk()->assertSee('Ikere Grammar School')->assertSee('to teach Chemistry');
    $this->actingAs($this->ada->user)->get(route('my.tp'))->assertOk()->assertSee('Ikere Grammar School');
    $this->get(route('my.tp.letter', $bayo))->assertNotFound();

    // The supervisor for Ikere marks Ada, but cannot mark Bayo at Ado.
    $this->actingAs($this->coordinator)->post(route('tp.exercises.supervisors.store', $ex), ['person' => $this->supervisor->email, 'school_id' => $this->school1->id])->assertSessionHas('success');
    $marks = ['lesson_plan' => 16, 'introduction' => 8, 'presentation_and_mastery_of_content' => 24, 'class_management' => 12, 'use_of_instructional_materials' => 8, 'evaluation_and_conclusion' => 12];
    $this->actingAs($this->supervisor)->get(route('tp.supervise'))->assertOk()->assertSee('Student Ada')->assertDontSee('Student Bayo');
    $this->post(route('tp.assess.store', $ada), ['visit_no' => 1, 'visited_on' => '2026-11-02', 'marks' => $marks])->assertSessionHas('success', fn ($m) => str_contains($m, '80.00%'));
    $this->post(route('tp.assess.store', $ada), ['visit_no' => 2, 'visited_on' => '2026-11-02', 'marks' => ['lesson_plan' => 25] + $marks])->assertSessionHas('error', fn ($m) => str_contains($m, 'out of 20'));
    $this->post(route('tp.assess.store', $ada), ['visit_no' => 2, 'visited_on' => '2026-11-02', 'marks' => ['presentation_and_mastery_of_content' => 18] + $marks])->assertSessionHas('success', fn ($m) => str_contains($m, '74.00%'));
    $this->get(route('tp.assess', $bayo))->assertForbidden();
});

it('sends final scores to the course result sheet split between CA and exam', function () {
    $ex = tpExercise($this);
    $this->post(route('tp.exercises.schools', $ex), ['capacity' => [$this->school1->id => 5]]);
    $this->post(route('tp.exercises.enrol', $ex));
    $this->post(route('tp.exercises.auto-post', $ex));
    foreach ([$this->ada, $this->bayo] as $s) {
        RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'level' => 400, 'status' => 'approved', 'total_units' => 6])
            ->courses()->create(['student_id' => $s->id, 'course_id' => $this->course->id, 'credit_units' => 6]);
    }
    $ada = TpPosting::where('student_id', $this->ada->id)->sole();
    $all = ['lesson_plan' => 18, 'introduction' => 9, 'presentation_and_mastery_of_content' => 21, 'class_management' => 10, 'use_of_instructional_materials' => 7, 'evaluation_and_conclusion' => 10]; // 75
    foreach ([1, 2] as $v) {
        $this->post(route('tp.assess.store', $ada), ['visit_no' => $v, 'visited_on' => '2026-11-02', 'marks' => $all])->assertSessionHas('success');
    }

    $this->post(route('tp.exercises.publish', $ex))->assertSessionHas('success', fn ($m) => str_contains($m, '1 score(s) written to the EDU 411 result sheet'))
        ->assertSessionHas('skipped', fn ($s) => count($s) === 1 && str_contains($s[0], 'Student Bayo') && str_contains($s[0], 'not every visit'));
    $r = CourseResult::where('student_id', $this->ada->id)->where('course_id', $this->course->id)->sole();
    expect($r->ca)->toBe(22.5)->and($r->exam)->toBe(52.5)->and($r->total)->toBe(75.0)->and((float) $ada->fresh()->final_score)->toBe(75.0);

    $this->get(route('tp.exercises.show', $ex))->assertOk()->assertSee('75%');
    $this->get(route('tp.exercises.show', [$ex, 'tab' => 'schools']))->assertOk();
    $this->get(route('tp.exercises.show', [$ex, 'tab' => 'supervisors']))->assertOk();
    $this->get(route('tp.exercises.export', $ex))->assertOk();
    $this->get(route('tp.exercises.index'))->assertOk();
    $this->get(route('tp.schools.index'))->assertOk();
    $this->get(route('tp.exercises.edit', $ex))->assertOk();
    $this->actingAs($this->junior->user)->get(route('tp.exercises.index'))->assertForbidden();
});
