<?php

use App\Models\AcademicSession;
use App\Models\AssessmentAssignment;
use App\Models\AssessmentScheme;
use App\Models\AssessmentScore;
use App\Services\AssessmentService;
use App\Models\Applicant;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseResult;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\RegistrationForm;
use App\Models\ResultSheet;
use App\Models\ResultStage;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\ResultService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $this->programme = Programme::create(['department_id' => $dept->id, 'name' => 'B.Ed. Biology', 'code' => 'BED-BIO', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $this->type = SemesterType::where('sort_order', 1)->first();
    $this->course = Course::create(['department_id' => $dept->id, 'code' => 'BIO 101', 'title' => 'Intro Biology', 'credit_units' => 3, 'level' => 100]);
    ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $this->course->id, 'level' => 100, 'semester_order' => 1, 'status' => 'core']);
    $this->course2 = Course::create(['department_id' => $dept->id, 'code' => 'BIO 102', 'title' => 'Cell Biology', 'credit_units' => 2, 'level' => 100]);

    $this->students = collect([['Tola', 'Adeyemi', 'a@example.com', '11111111AA', '2026/0001'], ['Bisi', 'Okon', 'b@example.com', '22222222BB', '2026/0002']])->map(function ($d, $i) {
        $a = Applicant::create(['first_name' => $d[0], 'last_name' => $d[1], 'email' => $d[2], 'jamb_reg_no' => $d[3], 'jamb_score' => 200, 'programme_id' => $this->programme->id,
            'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'status' => 'pending', 'password' => 'my-secret-pw']);
        $a->update(['application_no' => 'APP/2026/000' . ($i + 1)]);
        $s = app(AdmissionService::class)->admit($a->fresh(), 'First Batch')->student;
        $s->update(['needs_onboarding' => false, 'matric_number' => $d[4]]);
        RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'level' => 100, 'status' => 'approved', 'total_units' => 3])
            ->courses()->create(['student_id' => $s->id, 'course_id' => $this->course->id, 'credit_units' => 3]);

        return $s;
    });

    $this->role = Role::create(['name' => 'Lecturer']);
    $this->lecturer = User::factory()->create();
    $this->lecturer->assignRole('Lecturer');
    CourseAssignment::create(['user_id' => $this->lecturer->id, 'course_id' => $this->course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]);
    $this->admin->assignRole('Super Admin');
    $this->schemes = app(AssessmentService::class);
});

function makeScheme(array $items, string $name = 'Tests and exam'): AssessmentScheme
{
    return app(AssessmentService::class)->save(null, ['name' => $name], $items, test()->admin);
}

function standardScheme(): AssessmentScheme
{
    return makeScheme([
        ['name' => 'Test 1', 'code' => 'T1', 'component' => 'ca', 'max_score' => 10],
        ['name' => 'Test 2', 'code' => 'T2', 'component' => 'ca', 'max_score' => 10],
        ['name' => 'Assignment', 'code' => 'ASG', 'component' => 'ca', 'max_score' => 10],
        ['name' => 'Examination', 'code' => 'EXAM', 'component' => 'exam', 'max_score' => 70],
    ]);
}

function itemIds(AssessmentScheme $s): array
{
    return $s->items->pluck('id', 'code')->all();
}

it('lets an admin build a scheme that must add up to 100, and shows the pages', function () {
    $this->actingAs($this->admin)->get(route('academics.assessments.index'))->assertOk()->assertSee('Assessment Schemes');
    $this->get(route('academics.assessments.create'))->assertOk();

    $this->post(route('academics.assessments.store'), ['name' => 'Bad', 'items' => [
        ['name' => 'Test', 'component' => 'ca', 'max_score' => 30], ['name' => 'Exam', 'component' => 'exam', 'max_score' => 60],
    ]])->assertSessionHasErrors('items');
    expect(AssessmentScheme::count())->toBe(0);

    $this->post(route('academics.assessments.store'), ['name' => 'Practical courses', 'is_active' => 1, 'items' => [
        ['name' => 'Test 1', 'component' => 'ca', 'max_score' => 15],
        ['name' => 'Practical', 'code' => 'prac', 'component' => 'ca', 'max_score' => 15],
        ['name' => '', 'max_score' => ''],
        ['name' => 'Examination', 'component' => 'exam', 'max_score' => 70],
    ]])->assertSessionHasNoErrors()->assertRedirect(route('academics.assessments.index'));
    $s = AssessmentScheme::with('items')->first();
    expect($s->items->pluck('code')->all())->toBe(['T1', 'PRAC', 'E'])
        ->and($s->caMax())->toBe(30.0)->and($s->examMax())->toBe(70.0);
    $this->get(route('academics.assessments.edit', $s->id))->assertOk()->assertSee('Practical courses');

    // duplicate codes are refused
    expect(fn () => makeScheme([['name' => 'A', 'code' => 'X', 'max_score' => 50], ['name' => 'B', 'code' => 'x', 'max_score' => 50]]))
        ->toThrow(RuntimeException::class, 'short code');
});

it('keeps lecturers without the permission out of the scheme pages', function () {
    $this->actingAs($this->lecturer)->get(route('academics.assessments.index'))->assertForbidden();
    $this->post(route('academics.assessments.store'), ['name' => 'X', 'items' => [['name' => 'E', 'max_score' => 100]]])->assertForbidden();
});

it('picks the most specific scheme: course over department over faculty over university, one session over every session', function () {
    $uni = standardScheme();
    $dept = makeScheme([['name' => 'Coursework', 'max_score' => 40], ['name' => 'Exam', 'component' => 'exam', 'max_score' => 60]], 'Department');
    $course = makeScheme([['name' => 'Project', 'max_score' => 100]], 'Project');
    $thisSession = makeScheme([['name' => 'Quiz', 'max_score' => 20], ['name' => 'Exam', 'component' => 'exam', 'max_score' => 80]], 'This session');

    $resolve = fn () => app(AssessmentService::class)->resolve($this->course->fresh(), $this->session->id)[0]?->name;
    expect($resolve())->toBeNull();

    $this->actingAs($this->admin)->post(route('academics.assessments.assign'), ['assessment_scheme_id' => $uni->id, 'scope_type' => 'university'])->assertSessionHas('success');
    expect($resolve())->toBe('Tests and exam');
    $this->post(route('academics.assessments.assign'), ['assessment_scheme_id' => $dept->id, 'scope_type' => 'department', 'department_id' => $this->course->department_id])->assertSessionHas('success');
    expect($resolve())->toBe('Department');
    $this->post(route('academics.assessments.assign'), ['assessment_scheme_id' => $thisSession->id, 'scope_type' => 'department', 'department_id' => $this->course->department_id, 'academic_session_id' => $this->session->id]);
    expect($resolve())->toBe('This session');
    $this->post(route('academics.assessments.assign'), ['assessment_scheme_id' => $course->id, 'scope_type' => 'course', 'course_id' => $this->course->id]);
    expect($resolve())->toBe('Project');

    // the other course in the department is not affected by the course assignment
    expect(app(AssessmentService::class)->resolve($this->course2->fresh(), $this->session->id)[0]->name)->toBe('This session');

    // a scheme that is switched off is skipped
    $course->update(['is_active' => false]);
    expect($resolve())->toBe('This session');

    $this->get(route('academics.assessments.index', ['course_id' => $this->course->id]))->assertOk()->assertSee('This session');
    $a = AssessmentAssignment::where('assessment_scheme_id', $thisSession->id)->first();
    $this->delete(route('academics.assessments.unassign', $a->id))->assertSessionHas('success');
    expect(AssessmentAssignment::count())->toBe(3);
});

it('lets the lecturer enter a mark per assessment and adds them up to CA, exam, total and grade', function () {
    $scheme = standardScheme();
    $this->schemes->assign($scheme, 'university', null, null, $this->admin);
    $sheet = app(ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
    expect($sheet->fresh()->assessment_scheme_id)->toBe($scheme->id);
    $id = itemIds($scheme);
    [$s1, $s2] = [$this->students[0]->id, $this->students[1]->id];

    $this->actingAs($this->lecturer)->get(route('results.sheets.show', $sheet->id))->assertOk()->assertSee('T1')->assertSee('EXAM')->assertSee('Tests and exam');

    // first test only: saved, but no CA yet
    $this->post(route('results.sheets.save', $sheet->id), ['scores' => [$s1 => ['items' => [$id['T1'] => 8]], $s2 => ['items' => [$id['T1'] => 4]]]])->assertSessionHas('success');
    $r1 = CourseResult::where('student_id', $s1)->first();
    expect($r1->ca)->toBeNull()->and(AssessmentScore::count())->toBe(2);

    // the rest; a mark above an item's maximum is refused for that student only
    $this->post(route('results.sheets.save', $sheet->id), ['scores' => [
        $s1 => ['items' => [$id['T1'] => 8, $id['T2'] => 9.5, $id['ASG'] => 7, $id['EXAM'] => 50]],
        $s2 => ['items' => [$id['T2'] => 11, $id['ASG'] => 5, $id['EXAM'] => 20]],
    ]])->assertSessionHas('warning', fn ($m) => str_contains($m, 'Test 2 must be a number from 0 to 10'));
    $r1->refresh();
    expect($r1->ca)->toBe(24.5)->and($r1->exam)->toBe(50.0)->and($r1->total)->toBe(74.5)->and($r1->grade)->toBe('A');
    expect(CourseResult::where('student_id', $s2)->first()->ca)->toBeNull()
        ->and(AssessmentScore::where('course_result_id', CourseResult::where('student_id', $s2)->value('id'))->count())->toBe(1);   // nothing of the bad row was written

    // cannot submit while marks are missing
    $this->post(route('results.sheets.submit', $sheet->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'no mark for one or more assessments'));
    $this->post(route('results.sheets.save', $sheet->id), ['scores' => [$s2 => ['items' => [$id['T2'] => 6, $id['ASG'] => 5, $id['EXAM'] => 20]]]]);
    expect(CourseResult::where('student_id', $s2)->first()->total)->toBe(35.0);
    $this->post(route('results.sheets.submit', $sheet->id))->assertSessionHas('success');
});

it('reads uploads and writes the fill-in sheet with one column per assessment', function () {
    $scheme = standardScheme();
    $this->schemes->assign($scheme, 'course', $this->course->id, $this->session->id, $this->admin);
    $sheet = app(ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);

    $csv = "Matric Number,Name,T1 (10),Test 2,ASG,Examination\n2026/0001,Tola,7,8,9,55\n2026/0002,Bisi,5,5,5,\n9999/0001,Nobody,1,1,1,1\n";
    $this->actingAs($this->lecturer)->post(route('results.sheets.upload', $sheet->id), ['file' => UploadedFile::fake()->createWithContent('s.csv', $csv)])
        ->assertSessionHas('warning', fn ($m) => str_contains($m, '9999/0001'));
    expect(CourseResult::where('student_id', $this->students[0]->id)->first()->total)->toBe(79.0)
        ->and(CourseResult::where('student_id', $this->students[1]->id)->first()->ca)->toBe(15.0)
        ->and(CourseResult::where('student_id', $this->students[1]->id)->first()->exam)->toBeNull();

    $out = $this->get(route('results.sheets.template', $sheet->id))->assertOk()->getContent();
    expect($out)->toContain('"T1 (10)"')->toContain('"EXAM (70)"')->toContain('"2026/0001","Adeyemi Tola",7,8,9,55');

    $this->post(route('results.sheets.upload', $sheet->id), ['file' => UploadedFile::fake()->createWithContent('bad.csv', "Matric,Foo\n2026/0001,1\n")])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'T1, T2, ASG, EXAM'));
});

it('spreads a CA and exam over the assessments when only totals are given', function () {
    $scheme = standardScheme();
    $this->schemes->assign($scheme, 'university', null, null, $this->admin);
    $sheet = app(ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
    app(ResultService::class)->saveScores($sheet, [$this->students[0]->id => new \App\Data\Results\Score(25, 60)]);
    $r = CourseResult::where('student_id', $this->students[0]->id)->first();
    expect($r->ca)->toBe(25.0)->and($r->exam)->toBe(60.0)
        ->and(app(AssessmentService::class)->snapshot($r, $scheme->fresh('items')))->toBe(['T1' => 8.33, 'T2' => 8.33, 'ASG' => 8.34, 'EXAM' => 60.0]);
    expect(app(ResultService::class)->saveScores($sheet, [$this->students[0]->id => new \App\Data\Results\Score(35, 60)]))->toHaveCount(1);   // above the scheme's CA
});

it('keeps a sheet on its scheme once marks are in, and locks the scheme\'s assessments', function () {
    $first = standardScheme();
    $second = makeScheme([['name' => 'Exam', 'component' => 'exam', 'max_score' => 100]], 'Exam only');
    $this->schemes->assign($first, 'university', null, null, $this->admin);
    $sheet = app(ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
    $empty = app(ResultService::class)->sheet($this->course2->id, $this->session->id, $this->type->id);

    app(ResultService::class)->saveScores($sheet, [$this->students[0]->id => new \App\Data\Results\Score(items: ['T1' => 5])]);
    $this->schemes->assign($second, 'university', null, null, $this->admin);
    expect($sheet->fresh()->assessment_scheme_id)->toBe($first->id)
        ->and($empty->fresh()->assessment_scheme_id)->toBe($second->id);   // no marks yet: follows the change

    $this->actingAs($this->admin)->put(route('academics.assessments.update', $first->id), ['name' => 'Renamed', 'items' => [['name' => 'Exam', 'component' => 'exam', 'max_score' => 100]]])
        ->assertSessionHasErrors('items');
    $same = $first->items->map(fn ($i) => ['name' => $i->name, 'code' => $i->code, 'component' => $i->component, 'max_score' => $i->max_score])->all();
    $this->put(route('academics.assessments.update', $first->id), ['name' => 'Renamed', 'is_active' => 0, 'items' => $same])->assertSessionHasNoErrors();
    expect($first->fresh()->name)->toBe('Renamed')->and($first->fresh()->is_active)->toBeFalse();

    $this->delete(route('academics.assessments.destroy', $first->id))->assertSessionHas('error');
    $this->post(route('academics.assessments.duplicate', $first->id))->assertRedirect();
    expect(AssessmentScheme::where('name', 'Renamed (copy)')->first()->items)->toHaveCount(4);
});

it('leaves a sheet that already has plain CA and exam marks on the old split', function () {
    $sheet = app(ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
    app(ResultService::class)->saveScores($sheet, [$this->students[0]->id => new \App\Data\Results\Score(20, 50)]);
    $this->schemes->assign(standardScheme(), 'university', null, null, $this->admin);
    app(ResultService::class)->syncRows($sheet->fresh());
    expect($sheet->fresh()->assessment_scheme_id)->toBeNull();
});

it('corrects single assessments on a published result and can reverse the correction', function () {
    Setting::put('results.complaint_days', '0');
    ResultStage::query()->delete();
    $scheme = standardScheme();
    $this->schemes->assign($scheme, 'university', null, null, $this->admin);
    $sheet = app(ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
    $svc = app(ResultService::class);
    $svc->saveScores($sheet, [
        $this->students[0]->id => new \App\Data\Results\Score(items: ['T1' => 5, 'T2' => 5, 'ASG' => 5, 'EXAM' => 30]),
        $this->students[1]->id => new \App\Data\Results\Score(items: ['T1' => 5, 'T2' => 5, 'ASG' => 5, 'EXAM' => 30]),
    ]);
    $svc->submit($sheet->fresh(), $this->lecturer);
    $svc->publish($sheet->fresh(), $this->admin);
    $r = CourseResult::where('student_id', $this->students[0]->id)->first();
    $id = itemIds($scheme);

    $this->actingAs($this->admin)->get(route('results.sheets.show', $sheet->id))->assertOk()->assertSee('Correct a published score');
    $this->post(route('results.amend', $r->id), ['items' => [$id['T1'] => 5, $id['T2'] => 5, $id['ASG'] => 5, $id['EXAM'] => 45], 'reason' => 'Exam script re-marked'])->assertSessionHas('success');
    expect($r->fresh()->exam)->toBe(45.0)->and($r->fresh()->total)->toBe(60.0);

    // clearing a mark on a published result is refused and changes nothing
    $this->post(route('results.amend', $r->id), ['items' => [$id['EXAM'] => ''], 'reason' => 'x'])->assertSessionHas('error');
    expect($r->fresh()->exam)->toBe(45.0)->and(AssessmentScore::where('course_result_id', $r->id)->count())->toBe(4);

    $batch = \App\Models\OperationBatch::where('type', 'result_amend')->latest('id')->first();
    expect($batch->events()->first()->before['items'])->toEqual(['T1' => 5, 'T2' => 5, 'ASG' => 5, 'EXAM' => 30]);

    // reversing puts every assessment back as it was
    \Illuminate\Support\Facades\Mail::fake();
    $plan = \App\Support\Reversals::for($batch)->plan($batch);
    $this->post(route('operations.reverse.run', $batch), ['mode' => $plan->mode ?? 'reverse', 'fingerprint' => $plan->fingerprint(), 'reason' => 'Re-marking was cancelled.', 'confirm' => 1])->assertSessionHas('success');
    expect($r->fresh()->exam)->toBe(30.0)
        ->and(app(AssessmentService::class)->snapshot($r->fresh(), $scheme->fresh('items')))->toEqual(['T1' => 5, 'T2' => 5, 'ASG' => 5, 'EXAM' => 30]);
});

it('lets the exams office put a sheet that already has CA and exam marks on a scheme', function () {
    $sheet = app(ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
    app(ResultService::class)->saveScores($sheet, [$this->students[0]->id => new \App\Data\Results\Score(24, 50)]);
    $scheme = standardScheme();
    $small = makeScheme([['name' => 'Test', 'max_score' => 20], ['name' => 'Exam', 'component' => 'exam', 'max_score' => 80]], 'Small CA');

    $this->actingAs($this->lecturer)->post(route('results.sheets.scheme', $sheet->id), ['assessment_scheme_id' => $scheme->id])->assertForbidden();
    $this->actingAs($this->admin)->get(route('results.sheets.show', $sheet->id))->assertOk()->assertSee('Assessments on this sheet');

    // a CA of 24 does not fit a scheme whose CA is out of 20
    $this->post(route('results.sheets.scheme', $sheet->id), ['assessment_scheme_id' => $small->id])->assertSessionHas('error', fn ($m) => str_contains($m, '2026/0001'));

    $this->post(route('results.sheets.scheme', $sheet->id), ['assessment_scheme_id' => $scheme->id])->assertSessionHas('success');
    $r = CourseResult::where('student_id', $this->students[0]->id)->first();
    expect($sheet->fresh()->assessment_scheme_id)->toBe($scheme->id)->and($r->ca)->toBe(24.0)->and($r->exam)->toBe(50.0)
        ->and(app(AssessmentService::class)->snapshot($r, $scheme->fresh('items')))->toEqual(['T1' => 8, 'T2' => 8, 'ASG' => 8, 'EXAM' => 50]);

    // and the app shows the assessments to the lecturer
    $this->actingAs($this->lecturer);
    expect(\App\Data\Api\ApiScoreSheet::from($sheet->fresh(), true, 0)->toArray()['mode'])->toBe('items');

    // back to plain CA and exam
    $this->actingAs($this->admin)->post(route('results.sheets.scheme', $sheet->id), ['assessment_scheme_id' => ''])->assertSessionHas('success');
    expect($sheet->fresh()->assessment_scheme_id)->toBeNull()->and(AssessmentScore::count())->toBe(0);
});
