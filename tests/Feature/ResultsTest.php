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
    ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $this->course->id, 'level' => 100, 'semester_order' => 1, 'status' => 'core']);
    $this->course2 = Course::create(['department_id' => $dept->id, 'code' => 'BIO 102', 'title' => 'Cell Biology', 'credit_units' => 2, 'level' => 100]);

    $this->students = collect([['Tola', 'Adeyemi', 'a@example.com', '11111111AA', '2026/0001'], ['Bisi', 'Okon', 'b@example.com', '22222222BB', '2026/0002']])->map(function ($d, $i) {
        $a = Applicant::create(['first_name' => $d[0], 'last_name' => $d[1], 'email' => $d[2], 'jamb_reg_no' => $d[3], 'jamb_score' => 200, 'programme_id' => $this->programme->id,
            'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'status' => 'pending', 'password' => 'my-secret-pw']);
        $a->update(['application_no' => 'APP/2026/000' . ($i + 1)]);
        $s = app(AdmissionService::class)->admit($a->fresh(), 'First Batch')[0];
        $s->update(['needs_onboarding' => false, 'matric_number' => $d[4]]);
        RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'level' => 100, 'status' => 'approved', 'total_units' => 3])
            ->courses()->create(['student_id' => $s->id, 'course_id' => $this->course->id, 'credit_units' => 3]);

        return $s;
    });

    $this->role = Role::create(['name' => 'Lecturer']);
    $this->lecturer = User::factory()->create();
    $this->lecturer->assignRole('Lecturer');
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
    $this->get(route('results.sheets.template', $this->sheet->id))->assertOk()->assertSee('2026/0001');
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
    expect($t['terms'])->toHaveCount(2)->and($t['units'])->toBe(5)
        ->and($t['cgpa'])->toBe(4.6)            // (5*3 + 4*2) / 5 : the re-sit replaces the fail
        ->and($t['terms'][0]['gpa'])->toBe(1.6); // (0*3 + 4*2) / 5

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
