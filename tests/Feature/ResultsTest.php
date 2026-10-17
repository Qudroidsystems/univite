<?php

use App\Models\AcademicSession;
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
    ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $this->course->id, 'level' => 100, 'semester_order' => 1, 'status' => 'compulsory']);
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

    $this->role = Role::findByName('Lecturer');
    $this->lecturer = User::factory()->create();
    $this->lecturer->assignRole('Lecturer');
    \App\Models\StaffProfile::create(['user_id' => $this->lecturer->id, 'department_id' => $this->course->department_id, 'staff_number' => 'SP/9001']);
    CourseAssignment::create(['user_id' => $this->lecturer->id, 'course_id' => $this->course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]);
    $this->sheet = app(ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
});

function scoresFor(array $map): array
{
    return collect($map)->mapWithKeys(fn ($v, $i) => [test()->students[$i]->id => ['ca' => $v[0], 'exam' => $v[1]]])->all();
}

it('creates a sheet with a row for each student who registered for the course', function () {
    expect($this->sheet->results)->toHaveCount(2)->and($this->sheet->status)->toBe('draft');
});

it('lets the assigned lecturer enter scores, grades them from the scale, and refuses scores over the limit', function () {
    $this->actingAs($this->lecturer)->get(route('results.sheets.index'))->assertOk()->assertSee('BIO 101');
    $this->get(route('results.open', $this->course->id))->assertRedirect(route('results.sheets.show', $this->sheet->id));

    $this->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[25, 50], [10, 20]])])->assertSessionHas('success');
    $r = CourseResult::where('student_id', $this->students[0]->id)->first();
    expect($r->total)->toBe(75.0)->and($r->grade)->toBe('A')->and($r->points)->toBe(5.0);
    expect(CourseResult::where('student_id', $this->students[1]->id)->first()->grade)->toBe('F');

    $this->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[45, 50], [10, 20]])])->assertSessionHas('warning');
    expect($r->fresh()->ca)->toBe(25.0);   // the bad row was not applied
});

it('shows each course with its department, head of department and dean, and the sheet with the same', function () {
    $dept = $this->course->department;
    $hod = User::factory()->create(['name' => 'Kemi Hodson']);
    \App\Models\StaffProfile::create(['user_id' => $hod->id, 'department_id' => $dept->id, 'staff_number' => 'SP/0101']);
    $dean = User::factory()->create(['name' => 'Tunde Deanery']);
    $dept->update(['hod_id' => $hod->id]);
    $dept->faculty->update(['dean_id' => $dean->id]);

    $this->actingAs($this->lecturer)->get(route('results.sheets.index'))->assertOk()
        ->assertSee($dept->name)->assertSee('Kemi Hodson')->assertSee('Tunde Deanery')
        ->assertSee('data-hod="Kemi Hodson"', false);
    $this->actingAs($this->lecturer)->get(route('results.sheets.show', $this->sheet->id))->assertOk()
        ->assertSee('Course department')->assertSee('Kemi Hodson')->assertSee('SP/0101')->assertSee('Tunde Deanery');
});

it('keeps other lecturers out of the sheet', function () {
    $other = User::factory()->create();
    $this->actingAs($other)->get(route('results.sheets.show', $this->sheet->id))->assertForbidden();
    $this->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[1, 1], [1, 1]])])->assertForbidden();
});

it('reads a CSV upload by matric number and reports rows it cannot place', function () {
    $csv = "Matric Number,Name,CA,Exam\n2026/0001,Tola,28,60\n2026/0002,Bisi,20,35\n2026/9999,Ghost,10,10\n";
    $file = UploadedFile::fake()->createWithContent('scores.csv', $csv);
    $this->actingAs($this->lecturer)->post(route('results.sheets.upload', $this->sheet->id), ['file' => $file])->assertSessionHas('warning', fn ($m) => str_contains($m, '2026/9999'));

    expect(CourseResult::where('student_id', $this->students[0]->id)->value('total'))->toBe(88.0)
        ->and(CourseResult::where('student_id', $this->students[1]->id)->value('grade'))->toBe('C');

    $this->post(route('results.sheets.upload', $this->sheet->id), ['file' => UploadedFile::fake()->createWithContent('bad.csv', "Foo,Bar\n1,2\n")])->assertSessionHas('error');
    $this->get(route('results.sheets.template', [$this->sheet->id, 'format' => 'csv']))->assertOk()->assertSee('2026/0001');
});

it('will not submit until every score is in, then locks the sheet', function () {
    $this->actingAs($this->lecturer);
    $this->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[20, 40]])]);
    $this->post(route('results.sheets.submit', $this->sheet->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'still have no'));

    $this->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[20, 40], [0, 0]])]);
    $this->post(route('results.sheets.submit', $this->sheet->id))->assertSessionHas('success');
    expect($this->sheet->fresh()->status)->toBe('submitted')->and($this->sheet->fresh()->stage->name)->toBe('Head of Department');

    $this->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[1, 1], [1, 1]])])->assertSessionHas('error');
});

it('moves through the approval chain, honours step roles, supports returning, and publishes', function () {
    $this->actingAs($this->lecturer);
    $this->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[25, 50], [10, 20]])]);
    $this->post(route('results.sheets.submit', $this->sheet->id));

    ResultStage::where('name', 'Head of Department')->update(['office_role' => 'HOD']);
    $hod = User::factory()->create();
    Role::create(['name' => 'HOD'])->givePermissionTo('Approve results');
    $hod->assignRole('HOD');
    $dean = User::factory()->create();
    $dean->givePermissionTo('Approve results');   // right permission, wrong role for step 1

    $this->actingAs($dean)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve'])->assertSessionHas('error');

    $this->actingAs($hod)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'return'])->assertSessionHas('error');   // note required
    $this->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'return', 'note' => 'Check Okon\'s exam score'])->assertSessionHas('success');
    expect($this->sheet->fresh()->status)->toBe('draft');

    $this->actingAs($this->lecturer)->post(route('results.sheets.submit', $this->sheet->id));
    $this->actingAs($hod)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve']);
    expect($this->sheet->fresh()->stage->name)->toBe('Dean of Faculty');

    $this->actingAs($dean)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve']);
    expect($this->sheet->fresh()->status)->toBe('approved');

    // students cannot see approved-but-unpublished results
    $this->actingAs($this->students[0]->user->fresh())->get(route('my.results'))->assertOk()->assertSee('No results have been published yet');

    $this->actingAs($dean)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'publish'])->assertForbidden();
    $this->actingAs($this->admin)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'publish'])->assertSessionHas('success');
    expect($this->sheet->fresh()->status)->toBe('published')->and(CourseResult::where('status', 'published')->count())->toBe(2);
});

it('publishes straight after submission when there are no approval steps', function () {
    ResultStage::query()->delete();
    $this->actingAs($this->lecturer);
    $this->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[25, 50], [10, 20]])]);
    $this->post(route('results.sheets.submit', $this->sheet->id));
    expect($this->sheet->fresh()->status)->toBe('approved');
});

it('shows a student their published results with semester GPA and CGPA, and counts a re-sit once', function () {
    $s = $this->students[0];
    $make = fn ($course, $session, $total, $status = 'published') => CourseResult::create([
        'student_id' => $s->id, 'course_id' => $course->id, 'academic_session_id' => $session->id, 'semester_type_id' => $this->type->id, 'credit_units' => $course->credit_units,
        'ca' => 0, 'exam' => $total, 'total' => $total, 'grade' => \App\Support\GradeScale::grade($total)['grade'], 'points' => \App\Support\GradeScale::grade($total)['points'],
        'is_pass' => \App\Support\GradeScale::grade($total)['is_pass'], 'status' => $status,
    ]);
    $prev = AcademicSession::create(['name' => '2025/2026']);
    CourseResult::query()->delete();
    $make($this->course, $prev, 30);               // failed BIO 101 last year
    $make($this->course2, $prev, 65);              // B in BIO 102
    $make($this->course, $this->session, 72);      // re-sit: A
    $make($this->course2, $this->session, 10, 'approved');   // not published: invisible

    $t = app(ResultService::class)->transcript($s);
    expect($t->terms)->toHaveCount(2)->and($t->units)->toBe(5)
        ->and($t->cgpa)->toBe(4.6)            // (5*3 + 4*2) / 5 : the re-sit replaces the fail
        ->and($t->terms[0]->gpa)->toBe(1.6); // (0*3 + 4*2) / 5

    $this->actingAs($s->user->fresh())->get(route('my.results'))->assertOk()->assertSee('4.60')->assertSee('2025/2026');
    $this->actingAs($this->admin)->get(route('students.transcript', $s->id))->assertOk()->assertSee('Academic transcript');
    $this->actingAs($this->students[1]->user->fresh())->get(route('students.transcript', $s->id))->assertForbidden();
});

it('feeds published results back into registration as passes and carry-overs', function () {
    $s = $this->students[1];
    CourseResult::where('student_id', $s->id)->update(['credit_units' => 3, 'total' => 20, 'grade' => 'F', 'points' => 0, 'is_pass' => false, 'status' => 'published']);
    expect(app(\App\Services\RegistrationService::class)->failed($s)->all())->toBe([$this->course->id]);
});

it('manages lecturer assignments, approval steps and the mark split', function () {
    $this->actingAs($this->admin)->get(route('academics.course-assignments.index'))->assertOk()->assertSee('BIO 101');
    $this->post(route('academics.course-assignments.store'), ['user_id' => $this->lecturer->id, 'course_id' => $this->course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id])
        ->assertSessionHasErrors('semester_type_id');   // duplicate

    $this->post(route('academics.result-stages.store'), ['name' => 'Senate', 'sort_order' => 3, 'is_active' => 1])->assertSessionHasNoErrors();
    expect(ResultStage::chain()->pluck('name')->all())->toBe(['Head of Department', 'Dean of Faculty', 'Senate']);

    $this->put(route('results.settings.update'), ['ca_max' => 40, 'exam_max' => 50])->assertSessionHasErrors('exam_max');
    $this->put(route('results.settings.update'), ['ca_max' => 40, 'exam_max' => 60])->assertSessionHas('success');
    expect(ResultService::caMax())->toBe(40.0);
});

it('reads an Excel upload too', function () {
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $book->getActiveSheet()->fromArray([['Matric No', 'CA', 'Exam'], ['2026/0001', 30, 70], ['2026/0002', 5, 5]]);
    $path = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

    $this->actingAs($this->lecturer)->post(route('results.sheets.upload', $this->sheet->id), ['file' => new UploadedFile($path, 'scores.xlsx', null, null, true)])->assertSessionHas('success');
    expect(CourseResult::where('student_id', $this->students[0]->id)->value('grade'))->toBe('A')
        ->and(CourseResult::where('student_id', $this->students[1]->id)->value('total'))->toBe(10.0);
})->skip(! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class), 'PhpSpreadsheet not installed');

it('corrects a published score with a logged reason, and refuses for lack of permission or reason', function () {
    $this->actingAs($this->lecturer)->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[20, 40], [10, 20]])]);
    $this->post(route('results.sheets.submit', $this->sheet->id));
    $this->actingAs($this->admin);
    foreach (['approve', 'approve', 'publish'] as $d) {
        $this->post(route('results.sheets.decide', $this->sheet->id), ['decision' => $d]);
    }
    $row = CourseResult::where('student_id', $this->students[1]->id)->first();
    expect($row->status)->toBe('published');

    $this->actingAs($this->lecturer)->post(route('results.amend', $row->id), ['ca' => 25, 'exam' => 60, 'reason' => 'Mis-keyed'])->assertForbidden();
    $this->actingAs($this->admin)->post(route('results.amend', $row->id), ['ca' => 25, 'exam' => 60])->assertSessionHasErrors('reason');
    $this->post(route('results.amend', $row->id), ['ca' => 25, 'exam' => 60, 'reason' => 'Mis-keyed exam score'])->assertSessionHas('success');

    expect($row->fresh()->grade)->toBe('A')->and($this->sheet->fresh()->events()->first()->note)->toContain('Mis-keyed exam score')->toContain('2026/0002');
});

it('builds a broadsheet of published results for a programme and level', function () {
    $this->actingAs($this->lecturer)->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[28, 62], [10, 20]])]);
    $this->post(route('results.sheets.submit', $this->sheet->id));
    $this->actingAs($this->admin);
    foreach (['approve', 'approve', 'publish'] as $d) {
        $this->post(route('results.sheets.decide', $this->sheet->id), ['decision' => $d]);
    }
    $q = ['programme_id' => $this->programme->id, 'level' => 100, 'session_id' => $this->session->id, 'semester_type_id' => $this->type->id];
    $this->get(route('results.broadsheet', $q))->assertOk()->assertSee('BIO 101')->assertSee('90 A')->assertSee('30 F')->assertSee('5.00');
    $this->get(route('results.broadsheet'))->assertOk();
});

function publishSheet($test, array $scores): void
{
    $test->actingAs($test->lecturer)->post(route('results.sheets.save', $test->sheet->id), ['scores' => scoresFor($scores)]);
    $test->post(route('results.sheets.submit', $test->sheet->id));
    $test->actingAs($test->admin);
    foreach (['approve', 'approve', 'publish'] as $d) {
        $test->post(route('results.sheets.decide', $test->sheet->id), ['decision' => $d]);
    }
}

it('lets a student query a published result once, inside the time limit, and staff answer and correct it', function () {
    publishSheet($this, [[20, 40], [10, 20]]);
    $s = $this->students[1];
    $row = CourseResult::where('student_id', $s->id)->first();
    $other = CourseResult::where('student_id', $this->students[0]->id)->first();

    $this->actingAs($s->user->fresh())->get(route('my.results'))->assertOk()->assertSee('Query this result');
    $this->post(route('my.results.query', $other->id), ['reason' => 'Not mine'])->assertSessionHas('error');
    $this->post(route('my.results.query', $row->id), ['reason' => 'My exam score is missing a section'])->assertSessionHas('success');
    $this->post(route('my.results.query', $row->id), ['reason' => 'Again'])->assertSessionHas('error', fn ($m) => str_contains($m, 'already'));
    $complaint = \App\Models\ResultComplaint::firstOrFail();

    $this->actingAs($this->lecturer)->get(route('results.complaints'))->assertForbidden();
    $this->actingAs($this->lecturer)->get(route('results.sheets.show', $this->sheet->id))->assertSee('My exam score is missing a section');

    $clerk = User::factory()->create();
    $clerk->givePermissionTo('View result complaints', 'Respond to result complaints');
    $this->actingAs($clerk)->get(route('results.complaints'))->assertOk()->assertSee('My exam score is missing a section');
    $this->post(route('results.complaints.respond', $complaint->id), ['decision' => 'upheld', 'response' => 'Re-marked', 'ca' => 25, 'exam' => 50])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'not allowed'));

    $this->actingAs($this->admin)->post(route('results.complaints.respond', $complaint->id), ['decision' => 'upheld', 'response' => 'Script re-marked', 'ca' => 25, 'exam' => 50])->assertSessionHas('success');
    expect($row->fresh()->grade)->toBe('A')->and($complaint->fresh()->status)->toBe('upheld')
        ->and($this->sheet->fresh()->events()->first()->note)->toContain('Script re-marked');
    $this->actingAs($s->user->fresh())->get(route('my.results'))->assertSee('Script re-marked');

    // the window closes
    Setting::put('results.complaint_days', '7');
    $this->travel(8)->days();
    $this->actingAs($this->students[0]->user->fresh())->post(route('my.results.query', $other->id), ['reason' => 'Too late'])->assertSessionHas('error', fn ($m) => str_contains($m, '7 days'));
});

it('checks final-year students for graduation, holds back on request and graduates the eligible on approval', function () {
    $this->programme->update(['duration_years' => 1]);
    app(\App\Support\AcademicRules::class)->save('programme', $this->programme->id, null, null, ['graduation_units' => 3], null);
    publishSheet($this, [[25, 50], [10, 20]]);   // student 0 passes BIO 101, student 1 fails it
    $this->students->each->update(['current_level' => 100, 'status' => 'active']);

    $this->actingAs($this->admin)->post(route('graduation.run'), ['programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id])->assertRedirect();
    $list = \App\Models\GraduationList::firstOrFail();
    $entries = $list->entries->keyBy('student_id');
    expect($entries)->toHaveCount(2)
        ->and($entries[$this->students[0]->id]->is_eligible)->toBeTrue()
        ->and($entries[$this->students[0]->id]->degree_class)->not->toBeNull()
        ->and($entries[$this->students[1]->id]->is_eligible)->toBeFalse()
        ->and(implode(' ', $entries[$this->students[1]->id]->problems))->toContain('BIO 101')->toContain('0 of the 3 units');

    $this->get(route('graduation.show', $list->id))->assertOk()->assertSee('Eligible')->assertSee('Failed and not yet passed');

    // hold back needs a reason; putting back works
    $e0 = $entries[$this->students[0]->id];
    $this->post(route('graduation.exclude', $e0->id), ['exclude' => 1])->assertSessionHas('error');
    $this->post(route('graduation.exclude', $e0->id), ['exclude' => 1, 'note' => 'Owes library'])->assertSessionHas('success');
    $this->post(route('graduation.approve', $list->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'No student'));
    $this->post(route('graduation.exclude', $e0->id), ['exclude' => 0])->assertSessionHas('success');

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('View graduation lists');
    $this->actingAs($viewer)->post(route('graduation.approve', $list->id))->assertForbidden();

    $this->actingAs($this->admin)->post(route('graduation.approve', $list->id))->assertSessionHas('success', '1 student(s) graduated.');
    $s0 = $this->students[0]->fresh();
    expect($s0->status)->toBe('graduated')->and($s0->graduated_at)->not->toBeNull()->and($s0->final_cgpa)->toBe(5.0)
        ->and($this->students[1]->fresh()->status)->toBe('active')->and($list->fresh()->status)->toBe('approved');

    $this->post(route('graduation.run'), ['programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id])->assertSessionHas('error');
    $this->get(route('students.transcript', $s0->id))->assertSee('Graduated');
    $this->get(route('graduation.index'))->assertOk()->assertSee('B.Ed. Biology')->assertSee('Approved');
    $this->get(route('results.settings'))->assertOk()->assertSee('query a result');
    $this->get(route('academics.programmes.index'))->assertOk()->assertSee('Credit load');
});

it('stops graduation while registered results are unpublished or compulsory courses were never taken', function () {
    $this->programme->update(['duration_years' => 1]);
    ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $this->course2->id, 'level' => 100, 'semester_order' => 2, 'status' => 'compulsory']);
    $this->students->each->update(['current_level' => 100, 'status' => 'active']);

    $c = app(\App\Services\GraduationService::class)->check($this->students[0]->fresh());
    expect(implode(' ', $c->problems))->toContain('Results not yet published for: BIO 101')->toContain('never taken: BIO 102');

    Setting::put('graduation.require_core', '0');
    $c = app(\App\Services\GraduationService::class)->check($this->students[0]->fresh());
    expect(implode(' ', $c->problems))->not->toContain('never taken');
});

it('applies the programme\'s graduation rules: failed electives, elective units, minimum CGPA and years of study', function () {
    $this->programme->update(['duration_years' => 1]);
    ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $this->course2->id, 'level' => 100, 'semester_order' => 1, 'status' => 'elective']);
    $s = $this->students[0];
    $s->update(['current_level' => 100, 'status' => 'active']);
    CourseResult::query()->delete();
    $mk = fn ($course, $total) => CourseResult::create(['student_id' => $s->id, 'course_id' => $course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id,
        'credit_units' => $course->credit_units, 'ca' => 0, 'exam' => $total, 'total' => $total, 'grade' => \App\Support\GradeScale::grade($total)['grade'],
        'points' => \App\Support\GradeScale::grade($total)['points'], 'is_pass' => \App\Support\GradeScale::grade($total)['is_pass'], 'status' => 'published']);
    $mk($this->course, 50);    // C in BIO 101 (core)
    $mk($this->course2, 20);   // F in BIO 102 (elective)
    $rules = app(\App\Support\AcademicRules::class);
    $check = fn () => implode(' ', app(\App\Services\GraduationService::class)->check($s->fresh())->problems);

    expect($check())->toContain('Failed and not yet passed: BIO 102');
    $rules->save('department', $this->programme->department_id, null, null, ['retake_failed_electives' => false], null);
    expect($check())->toBe('');

    $rules->save('programme', $this->programme->id, null, null, ['min_elective_units' => 2, 'min_graduation_cgpa' => 4.0, 'max_years' => 1], null);
    $prev = AcademicSession::create(['name' => '2025/2026']);
    RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => $prev->id, 'semester_type_id' => $this->type->id, 'level' => 100, 'status' => 'approved', 'total_units' => 0]);
    expect($check())->toContain('0 of the 2 elective units')->toContain('below the 4.00 needed')->toContain('2 sessions');
});

it('counts every attempt under the TCP/TNU rule and shows probation from the programme\'s rules', function () {
    $s = $this->students[0];
    CourseResult::query()->delete();
    $prev = AcademicSession::create(['name' => '2025/2026']);
    foreach ([[$prev, 20], [$this->session, 70]] as [$sess, $total]) {
        CourseResult::create(['student_id' => $s->id, 'course_id' => $this->course->id, 'academic_session_id' => $sess->id, 'semester_type_id' => $this->type->id, 'credit_units' => 3,
            'ca' => 0, 'exam' => $total, 'total' => $total, 'grade' => \App\Support\GradeScale::grade($total)['grade'], 'points' => \App\Support\GradeScale::grade($total)['points'],
            'is_pass' => \App\Support\GradeScale::grade($total)['is_pass'], 'status' => 'published']);
    }
    expect(app(ResultService::class)->transcript($s)->cgpa)->toBe(5.0);

    $rules = app(\App\Support\AcademicRules::class);
    $rules->save('programme', $this->programme->id, null, null, ['cgpa_method' => 'all', 'probation_cgpa' => 3.0], null);
    $t = app(ResultService::class)->transcript($s);
    expect($t->cgpa)->toBe(2.5)->and($t->units)->toBe(6)->and($t->standing)->toBe('On probation');
    $this->actingAs($s->user->fresh())->get(route('my.results'))->assertSee('On probation')->assertSee('TNU');
});

it('puts weak students on probation or advises withdrawal, limits or holds their registration, and lets the department allow them', function () {
    publishSheet($this, [[25, 50], [10, 20]]);   // student 0: CGPA 5, student 1: CGPA 0
    [$good, $weak] = [$this->students[0]->fresh(), $this->students[1]->fresh()];
    $rules = app(\App\Support\AcademicRules::class);
    $rules->save('department', $this->programme->department_id, null, null, ['probation_cgpa' => 5.5, 'withdrawal_cgpa' => 1.0, 'probation_max_units' => 3], null);
    $reg = app(\App\Services\RegistrationService::class);

    expect($reg->standing($good))->toBe('On probation')->and($reg->limits($good, $reg->context())->max)->toBe(3)
        ->and($reg->standing($weak))->toBe('Advised to withdraw')
        ->and(implode(' ', $reg->blockers($weak)))->toContain('advised to withdraw');

    // the head of department sees the summary for their department and allows the weak student for this session
    Role::create(['name' => 'HOD'])->givePermissionTo('Edit department academic rules');
    $hod = User::factory()->create();
    $hod->assignRole('HOD');
    $this->programme->department->update(['hod_id' => $hod->id]);
    $q = ['department_id' => $this->programme->department_id, 'session_id' => $this->session->id, 'semester_type_id' => $this->type->id];
    $this->actingAs($hod)->get(route('results.summary', $q))->assertOk()->assertSee('Advised to withdraw (1)')->assertSee('On probation (1)')->assertSee('BIO 101');
    $this->post(route('results.summary.waive', $weak->id), ['note' => 'Senate approved'])->assertSessionHas('success');
    expect(implode(' ', $reg->blockers($weak)))->not->toContain('advised to withdraw');

    $this->actingAs($this->lecturer)->get(route('results.summary', $q))->assertForbidden();
    $this->post(route('results.summary.waive', $weak->id), ['note' => 'x'])->assertForbidden();
});

it('lets a course adviser approve the registration forms of the level they advise, and nothing else', function () {
    $form = RegistrationForm::where('student_id', $this->students[0]->id)->first();
    $form->update(['status' => 'submitted']);
    $adviser = User::factory()->create();

    $this->actingAs($adviser)->get(route('registrations.index'))->assertForbidden();
    $this->actingAs($this->admin)->post(route('academics.rules.advisers.store', $this->programme->id), ['level' => 100, 'user_id' => $adviser->id])->assertSessionHas('success');
    $this->get(route('academics.rules.index', ['scope' => 'programme', 'id' => $this->programme->id]))->assertSee('Course advisers')->assertSee($adviser->name);

    $this->actingAs($adviser)->get(route('registrations.index'))->assertOk()->assertSee('My advisees')->assertSee('Adeyemi');
    $this->post(route('registrations.decide', $form->id), ['decision' => 'approve'])->assertSessionHas('success');
    expect($form->fresh()->status)->toBe('approved');

    $form->update(['level' => 200, 'status' => 'submitted']);
    $this->post(route('registrations.decide', $form->id), ['decision' => 'approve'])->assertForbidden();
    $this->get(route('registrations.show', $form->id))->assertForbidden();
});

it('reverses a score correction as a new correction, and refuses once the score changed again', function () {
    \Illuminate\Support\Facades\Mail::fake();
    publishSheet($this, [[20, 40], [10, 20]]);
    $row = CourseResult::where('student_id', $this->students[1]->id)->first();
    $this->post(route('results.amend', $row->id), ['ca' => 25, 'exam' => 60, 'reason' => 'Mis-keyed exam score'])->assertSessionHas('success');
    \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\DocumentMail::class, fn ($m) => $m->hasTo('b@example.com') && str_contains($m->body, 'Result correction notice'));

    $op = \App\Models\OperationBatch::where('type', 'result_amend')->firstOrFail();
    $this->get(route('operations.reverse', $op))->assertOk()->assertSee('25 + 60 (A)')->assertSee('10 + 20 (F)');
    confirmReversal($this, $op)->assertSessionHas('success');
    expect($row->fresh()->total)->toBe(30.0)->and($op->fresh()->status)->toBe('reversed')
        ->and(\App\Models\OperationBatch::where('type', 'result_amend_reverse')->value('related_operation_id'))->toBe($op->id);

    // a second correction, then a later one: the earlier cannot be reversed any more
    $this->post(route('results.amend', $row->id), ['ca' => 20, 'exam' => 30, 'reason' => 'Second marking']);
    $second = \App\Models\OperationBatch::where('type', 'result_amend')->latest('id')->first();
    $this->post(route('results.amend', $row->id), ['ca' => 21, 'exam' => 30, 'reason' => 'Third marking']);
    $plan = \App\Support\Reversals::for($second)->plan($second);
    expect($plan->canRun())->toBeFalse()->and($plan->blockers[0])->toContain('changed again');
});

it('withdraws a published sheet formally, and refuses once a student on it has graduated', function () {
    \Illuminate\Support\Facades\Mail::fake();
    publishSheet($this, [[25, 50], [10, 20]]);
    $op = \App\Models\OperationBatch::where('type', 'result_publish')->firstOrFail();

    // permission
    $clerk = User::factory()->create();
    $clerk->givePermissionTo('View operation history');
    $this->actingAs($clerk)->get(route('operations.reverse', $op))->assertForbidden();

    // reason is required
    $this->actingAs($this->admin)->post(route('operations.reverse.run', $op), ['mode' => 'withdraw', 'fingerprint' => 'x', 'reason' => '', 'confirm' => 1])->assertSessionHasErrors('reason');

    $this->students[0]->update(['graduated_at' => now()]);
    $this->get(route('operations.reverse', $op))->assertOk()->assertSee('have graduated');
    expect(\App\Support\Reversals::for($op)->plan($op)->canRun())->toBeFalse();

    $this->students[0]->update(['graduated_at' => null]);
    confirmReversal($this, $op, 'withdraw', 'Senate set the BIO 101 result aside.')->assertSessionHas('success');
    expect($this->sheet->fresh()->status)->toBe('approved')
        ->and(CourseResult::where('result_sheet_id', $this->sheet->id)->pluck('status')->unique()->all())->toBe(['approved'])
        ->and(CourseResult::whereNotNull('total')->count())->toBe(2);   // scores are kept
    \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\DocumentMail::class, fn ($m) => str_contains($m->body, 'withdrawn for review'));

    // it can be published again normally
    $this->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'publish']);
    expect($this->sheet->fresh()->status)->toBe('published');
});

it('reverses a graduation approval back to the students\' earlier status and keeps the record', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $this->programme->update(['duration_years' => 1]);
    publishSheet($this, [[25, 50], [10, 20]]);
    $this->students->each->update(['current_level' => 100, 'status' => 'active']);
    $this->actingAs($this->admin)->post(route('graduation.run'), ['programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id]);
    $list = \App\Models\GraduationList::firstOrFail();
    $this->post(route('graduation.approve', $list->id))->assertSessionHas('success');
    \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\DocumentMail::class, fn ($m) => $m->hasTo('a@example.com') && str_contains($m->body, 'Graduation notice'));

    $op = \App\Models\OperationBatch::where('type', 'graduation_approve')->firstOrFail();
    expect($op->events()->where('action', 'student_graduated')->first()->before['status'])->toBe('active');
    confirmReversal($this, $op, null, 'Approved before the Senate meeting.')->assertSessionHas('success');

    $s0 = $this->students[0]->fresh();
    expect($s0->status)->toBe('active')->and($s0->graduated_at)->toBeNull()->and($s0->degree_class)->toBeNull()
        ->and($list->fresh()->status)->toBe('draft')->and($op->fresh()->status)->toBe('reversed')
        ->and($op->events()->count())->toBe(2);   // the original events (one student + the list) are untouched
    $this->get(route('operations.show', $op))->assertOk()->assertSee('Graduation approval reversed')->assertSee('Approved before the Senate meeting.');
});

it('lets an approver unlock and change scores, and still return the sheet, after approving it until it is published', function () {
    $this->actingAs($this->lecturer);
    $this->post(route('results.sheets.save', $this->sheet->id), ['scores' => scoresFor([[25, 50], [10, 20]])]);
    $this->post(route('results.sheets.submit', $this->sheet->id));

    $hod = User::factory()->create();
    Role::create(['name' => 'Approver'])->givePermissionTo('Approve results', 'Edit scores while approving');
    $hod->assignRole('Approver');
    $plain = User::factory()->create();
    $plain->givePermissionTo('Approve results');

    // Waiting at the HOD's step: the scores show locked, with a lock to click.
    $this->actingAs($hod)->get(route('results.sheets.show', $this->sheet->id))->assertOk()
        ->assertSee('data-lock', false)->assertSee('readonly data-lockable', false)->assertSee('Save changes');
    $this->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve']);
    expect($this->sheet->fresh()->stage->name)->toBe('Dean of Faculty');

    // Already approved by them: they can still change a score; only the changed row is touched.
    $okon = $this->students[1];
    $this->post(route('results.sheets.correct', $this->sheet->id), ['scores' => scoresFor([[25, 50], [15, 30]]), 'note' => 'Marking error'])
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'for 1 student'));
    $row = CourseResult::where('student_id', $okon->id)->first();
    expect((float) $row->total)->toBe(45.0)->and($row->status)->toBe('submitted')
        ->and($this->sheet->fresh()->status)->toBe('submitted')
        ->and($this->sheet->events()->where('action', 'corrected')->first()->note)->toContain('Marking error')->toContain('2026/0002 30 to 45');

    // Without "Edit scores while approving" the scores cannot be changed, even by an approver.
    $this->actingAs($plain)->post(route('results.sheets.correct', $this->sheet->id), ['scores' => scoresFor([[1, 1], [1, 1]])])->assertForbidden();

    // The HOD can still send it back after approving, even once it is fully approved, but not once it is published.
    $this->actingAs($plain)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve']);
    expect($this->sheet->fresh()->status)->toBe('approved');
    $this->actingAs($hod)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'return', 'note' => 'Recheck'])->assertSessionHas('success');
    expect($this->sheet->fresh()->status)->toBe('draft');

    $this->actingAs($this->lecturer)->post(route('results.sheets.submit', $this->sheet->id));
    $this->actingAs($hod)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve']);
    $this->actingAs($plain)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve']);
    $this->actingAs($this->admin)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'publish']);
    $this->actingAs($hod)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'return', 'note' => 'Too late'])->assertSessionHas('error');
    $this->post(route('results.sheets.correct', $this->sheet->id), ['scores' => scoresFor([[1, 1], [1, 1]])])->assertForbidden();
});

it('flags a duplicate course definition while the form is filled in, without saving anything', function () {
    $row = ['user_id' => $this->lecturer->id, 'course_id' => $this->course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id];
    $check = ['X-Uv-Check' => '1', 'Accept' => 'application/json'];
    $count = CourseAssignment::count();

    $this->actingAs($this->admin)->postJson(route('academics.course-assignments.store'), $row, $check)->assertStatus(422)
        ->assertJsonPath('errors.semester_type_id.0', 'This lecturer is already defined for this course in this session and semester.');
    $this->postJson(route('academics.course-assignments.store'), ['course_id' => $this->course2->id] + $row, $check)->assertOk()->assertJson(['ok' => true]);
    expect(CourseAssignment::count())->toBe($count);

    // Editing the record itself is not a duplicate of itself.
    $mine = CourseAssignment::where('user_id', $this->lecturer->id)->first();
    $this->putJson(route('academics.course-assignments.update', $mine->id), $row, $check)->assertOk();

    // Other setup lists refuse duplicates too, with the field's own name in the message.
    $this->postJson(route('academics.result-stages.store'), ['name' => 'Head of Department', 'sort_order' => 5], $check)->assertStatus(422)->assertJsonValidationErrors('name');
});
