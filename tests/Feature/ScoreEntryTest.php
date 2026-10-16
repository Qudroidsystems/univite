<?php

use App\Models\AssessmentScore;
use App\Models\Course;
use App\Models\CourseResult;
use App\Models\RegistrationForm;
use App\Models\ResultSheet;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\StudyCategory;
use App\Support\Xlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

beforeEach(function () {
    campusWorld($this);
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $this->type = SemesterType::where('sort_order', 1)->first();
    $this->degree = StudyCategory::firstOrCreate(['code' => 'DEG'], ['name' => 'Degree', 'duration_years' => 4]);
    $this->nce = StudyCategory::firstOrCreate(['code' => 'NCE'], ['name' => 'NCE', 'duration_years' => 3]);
    $this->prog->update(['study_category_id' => $this->degree->id]);
    $this->prog2->update(['study_category_id' => $this->degree->id]);
    $this->course = Course::create(['department_id' => $this->dept->id, 'code' => 'GST101', 'title' => 'Use of English', 'credit_units' => 2, 'level' => 100]);
    \App\Models\ProgrammeCourse::create(['programme_id' => $this->prog->id, 'course_id' => $this->course->id, 'level' => 100, 'semester_order' => 1]);
    \App\Models\ProgrammeCourse::create(['programme_id' => $this->prog2->id, 'course_id' => $this->course->id, 'level' => 100, 'semester_order' => 1]);

    $this->assessments = app(\App\Services\AssessmentService::class);
    $this->scheme = $this->assessments->save(null, ['name' => 'Three CAs and questions', 'ca_total' => 40, 'exam_total' => 60], [
        ['name' => 'CA 1', 'code' => 'CA1', 'component' => 'ca', 'max_score' => 10],
        ['name' => 'CA 2', 'code' => 'CA2', 'component' => 'ca', 'max_score' => 15],
        ['name' => 'CA 3', 'code' => 'CA3', 'component' => 'ca', 'max_score' => 15],
        ['name' => 'Question 1', 'code' => 'Q1', 'component' => 'exam', 'max_score' => 30],
        ['name' => 'Question 2', 'code' => 'Q2', 'component' => 'exam', 'max_score' => 30],
    ], $this->admin);
    $this->assessments->assign($this->scheme, 'university', null, null, $this->admin);

    // Two education students and one chemistry student take the course.
    $this->students = collect([['Ada', $this->prog], ['Bola', $this->prog], ['Chidi', $this->prog2]])->map(function ($x) {
        $s = makeStudent($this, $x[0], programme: $x[1]);
        RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'level' => 100, 'status' => 'approved', 'total_units' => 2])
            ->courses()->create(['student_id' => $s->id, 'course_id' => $this->course->id, 'credit_units' => 2]);

        return $s;
    });
    \App\Models\ResultStage::query()->delete();
    $this->lecturer = makeStaff($this, 'Grace Lecturer');
    \App\Models\CourseAssignment::create(['user_id' => $this->lecturer->id, 'course_id' => $this->course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]);
});

it('creates a course with its faculty, department, student category, level, session and semester', function () {
    $base = ['code' => 'edu  205', 'title' => 'Curriculum Studies', 'credit_units' => 3, 'level' => 200, 'study_category_id' => $this->nce->id,
        'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'is_active' => 1];

    // the department must belong to the faculty chosen
    $this->actingAs($this->admin)->post(route('academics.courses.store'), $base + ['faculty_id' => $this->faculty2->id, 'department_id' => $this->dept->id])
        ->assertSessionHasErrors('department_id');

    $this->actingAs($this->admin)->post(route('academics.courses.store'), $base + ['faculty_id' => $this->faculty->id, 'department_id' => $this->dept->id])->assertSessionHasNoErrors();
    $c = Course::where('code', 'EDU 205')->firstOrFail();
    expect($c->study_category_id)->toBe($this->nce->id)->and($c->semester_type_id)->toBe($this->type->id)
        ->and($c->academic_session_id)->toBe($this->session->id)->and($c->level)->toBe(200);
    $this->actingAs($this->admin)->get(route('academics.courses.index'))->assertOk()->assertSee('Faculty / school')->assertSee('Student category')->assertSee('NCE');
});

it('makes a scheme\'s assessments add up to the CA and exam totals the admin sets', function () {
    expect(fn () => $this->assessments->save(null, ['name' => 'Wrong', 'ca_total' => 40, 'exam_total' => 60], [
        ['name' => 'CA 1', 'component' => 'ca', 'max_score' => 30], ['name' => 'Exam', 'component' => 'exam', 'max_score' => 70],
    ], $this->admin))->toThrow(RuntimeException::class, 'CA assessments must add up to the CA total of 40');
    expect(fn () => $this->assessments->totals(50, 60))->toThrow(RuntimeException::class, 'add up to 100');
    expect($this->assessments->totals(35, null))->toBe([35.0, 65.0]);

    expect($this->scheme->fresh()->ca_total)->toEqual(40)->and($this->scheme->fresh()->exam_total)->toEqual(60);
    $this->actingAs($this->admin)->put(route('academics.assessments.update', $this->scheme->id), [
        'name' => 'Three CAs and questions', 'ca_total' => 30, 'exam_total' => 70, 'is_active' => 1,
        'items' => $this->scheme->items->map(fn ($i) => $i->only(['name', 'code', 'component', 'max_score']))->all(),
    ])->assertSessionHasErrors('items');
});

it('adds up assessments, and takes a CA or exam total typed directly', function () {
    $sheet = app(\App\Services\ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
    [$ada, $bola] = [$this->students[0], $this->students[1]];
    $items = $this->scheme->items->keyBy('code');

    $this->actingAs($this->lecturer)->post(route('results.sheets.save', $sheet->id), ['scores' => [
        // Ada: every assessment
        $ada->id => ['items' => [$items['CA1']->id => 8, $items['CA2']->id => 12, $items['CA3']->id => 10, $items['Q1']->id => 20, $items['Q2']->id => 25], 'ca' => 99, 'exam' => ''],
        // Bola: CA total typed directly, exam by questions
        $bola->id => ['items' => [$items['CA1']->id => '', $items['CA2']->id => '', $items['CA3']->id => '', $items['Q1']->id => 10, $items['Q2']->id => 15], 'ca' => 33, 'exam' => ''],
    ]])->assertSessionHas('success');

    $a = CourseResult::where('student_id', $ada->id)->first();
    $b = CourseResult::where('student_id', $bola->id)->first();
    expect([(float) $a->ca, (float) $a->exam, (float) $a->total])->toBe([30.0, 45.0, 75.0])->and($a->grade)->not->toBeNull()
        ->and([(float) $b->ca, (float) $b->exam, (float) $b->total])->toBe([33.0, 25.0, 58.0])
        ->and(AssessmentScore::where('course_result_id', $b->id)->count())->toBe(2);

    // a CA total above the CA total allowed is refused
    $this->actingAs($this->lecturer)->post(route('results.sheets.save', $sheet->id), ['scores' => [
        $bola->id => ['items' => [$items['CA1']->id => '', $items['CA2']->id => '', $items['CA3']->id => ''], 'ca' => 45],
    ]])->assertSessionHas('warning', fn ($m) => str_contains($m, 'from 0 to 40'));
    expect((float) $b->fresh()->ca)->toBe(33.0);

    // typing a mark for one CA again goes back to adding up (incomplete until all three are in)
    $this->actingAs($this->lecturer)->post(route('results.sheets.save', $sheet->id), ['scores' => [
        $bola->id => ['items' => [$items['CA1']->id => 9, $items['CA2']->id => '', $items['CA3']->id => ''], 'ca' => 9],
    ]]);
    expect($b->fresh()->ca)->toBeNull()->and($b->fresh()->total)->toBeNull();

    // the sheet page shows the live table
    $this->actingAs($this->lecturer)->get(route('results.sheets.show', $sheet->id))->assertOk()
        ->assertSee('data-score-table', false)->assertSee('CA total')->assertSee('univite-scores.js');
});

it('downloads a totals-only score sheet and reads its totals back', function () {
    $sheet = app(\App\Services\ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
    $res = $this->actingAs($this->lecturer)->get(route('results.sheets.template', [$sheet->id, 'type' => 'total']))->assertOk();
    expect($res->headers->get('Content-Disposition'))->toContain('_totals.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
    file_put_contents($path, $res->getContent());
    $rows = Xlsx::read($path);
    expect($rows[0])->toBe(['Matric Number', 'Name', 'CA total (40)', 'Exam total (60)', 'Total (100)', 'Grade'])->and(count($rows))->toBe(4);

    foreach ([1, 2, 3] as $k) {
        $rows[$k] = [$rows[$k][0], $rows[$k][1], 30 + $k, $k === 3 ? null : 50];
    }
    file_put_contents($path, Xlsx::write($rows, 'GST101'));
    $this->actingAs($this->lecturer)->post(route('results.sheets.upload', $sheet->id), ['file' => new UploadedFile($path, 'totals.xlsx', null, null, true)])->assertSessionHas('success');

    $results = $sheet->results()->get();
    expect($results->whereNotNull('total')->count())->toBe(2)
        ->and($results->pluck('ca')->map(fn ($v) => (float) $v)->sort()->values()->all())->toBe([31.0, 32.0, 33.0])
        ->and(AssessmentScore::whereIn('course_result_id', $results->pluck('id'))->count())->toBe(0);

    // the detailed sheet still lists every assessment, then the totals
    $detail = $this->actingAs($this->lecturer)->get(route('results.sheets.template', [$sheet->id, 'format' => 'csv']))->assertOk()->getContent();
    expect($detail)->toContain('"CA1 (10)","CA2 (15)","CA3 (15)","Q1 (30)","Q2 (30)","CA total (40)","Exam total (60)","Total (100)","Grade"');
});

it('lists everyone on a course by subject, and only the chosen class by student', function () {
    $by = fn (array $q) => $this->actingAs($this->lecturer)->get(route('results.entry', $q))->assertOk();
    $base = ['session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'course_id' => $this->course->id];

    $by(['mode' => 'subject'] + $base)->assertSee('GST101')->assertSee('Student Ada')->assertSee('Student Chidi')->assertSee("3 students on the course");

    $page = $by(['mode' => 'student', 'department_id' => $this->dept->id, 'study_category_id' => $this->degree->id, 'level' => 100] + $base);
    $page->assertSee('Student Ada')->assertSee('Student Bola')->assertDontSee('Student Chidi')->assertSee('2 students in Science Education');

    // post by student needs the class; another category shows nobody
    $by(['mode' => 'student'] + $base)->assertSee('Choose the department, student category, level as well.');
    $by(['mode' => 'student', 'department_id' => $this->dept->id, 'study_category_id' => $this->nce->id, 'level' => 100] + $base)->assertDontSee('Student Ada');

    // the course list follows the department and level; a lecturer only sees their own courses
    Course::create(['department_id' => $this->dept2->id, 'code' => 'CHM101', 'title' => 'General Chemistry', 'credit_units' => 3, 'level' => 100]);
    $by(['mode' => 'subject', 'session_id' => $this->session->id, 'semester_type_id' => $this->type->id])->assertSee('GST101')->assertDontSee('CHM101');
    $by(['mode' => 'subject', 'level' => 300] + $base)->assertSee('No course matches');

    // saving from here touches only the rows shown
    $items = $this->scheme->items->keyBy('code');
    $sheet = ResultSheet::firstOrFail();
    $this->actingAs($this->lecturer)->from(route('results.entry', ['mode' => 'student'] + $base))->post(route('results.sheets.save', $sheet->id), ['scores' => [
        $this->students[0]->id => ['ca' => 35, 'exam' => 55, 'items' => [$items['CA1']->id => '', $items['CA2']->id => '', $items['CA3']->id => '', $items['Q1']->id => '', $items['Q2']->id => '']],
    ]])->assertRedirect(route('results.entry', ['mode' => 'student'] + $base));
    expect((float) CourseResult::where('student_id', $this->students[0]->id)->value('total'))->toBe(90.0)
        ->and(CourseResult::where('student_id', $this->students[2]->id)->value('total'))->toBeNull();

    // someone with no courses cannot use the page
    $this->actingAs(makeStaff($this, 'No Courses'))->get(route('results.entry'))->assertForbidden();
});
