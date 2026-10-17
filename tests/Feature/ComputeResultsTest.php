<?php

use App\Models\ComputedResult;
use App\Models\GradeBand;
use App\Models\ResultKey;

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\CarryoverWaiver;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseResult;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\RegistrationForm;
use App\Models\ResultStage;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\RegistrationService;
use App\Services\ResultService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $this->hod = User::factory()->create(['name' => 'Head Of Science Ed']);
    $this->dean = User::factory()->create(['name' => 'Dean Of Education']);
    $this->otherHod = User::factory()->create(['name' => 'Head Of Arts Ed']);
    $faculty->update(['dean_id' => $this->dean->id]);
    $dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Science Education', 'code' => 'SED', 'hod_id' => $this->hod->id]);
    Department::create(['faculty_id' => $faculty->id, 'name' => 'Arts Education', 'code' => 'AED', 'hod_id' => $this->otherHod->id]);
    ResultStage::where('name', 'Head of Department')->update(['office_role' => 'Head of Department']);
    ResultStage::where('name', 'Dean of Faculty')->update(['office_role' => 'Dean']);
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
    CourseAssignment::create(['user_id' => $this->lecturer->id, 'course_id' => $this->course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]);
    $this->sheet = app(ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
});


function computeQuery(array $extra = []): array
{
    return $extra + ['department_id' => test()->course->department_id, 'level' => 100, 'session_id' => test()->session->id, 'semester_type_id' => test()->type->id];
}

/** Tola scores 75, Bisi 30; the sheet is submitted. */
function submitForCompute(): void
{
    $scores = collect([[25, 50], [10, 20]])->mapWithKeys(fn ($v, $i) => [test()->students[$i]->id => ['ca' => $v[0], 'exam' => $v[1]]])->all();
    test()->actingAs(test()->lecturer)->post(route('results.sheets.save', test()->sheet->id), ['scores' => $scores]);
    test()->post(route('results.sheets.submit', test()->sheet->id));
}

it('shows the head of department the submitted scores with who submitted them and when', function () {
    $this->lecturer->update(['name' => 'Dr Kemi Ade']);
    submitForCompute();

    $this->actingAs($this->hod)->get(route('results.compute'))->assertOk()->assertSee('Science Education');
    $this->get(route('results.compute', computeQuery()))->assertOk()
        ->assertSee('BIO 101')->assertSee('Dr Kemi Ade')->assertSee(now()->format('l'))->assertSee('2026/0001')->assertSee('not computed yet');

    // Another department's head cannot open it, nor can the lecturer.
    $this->actingAs($this->otherHod)->get(route('results.compute', computeQuery()))->assertForbidden();
    $this->actingAs($this->lecturer)->get(route('results.compute'))->assertForbidden();
});

it('computes with the department\'s own key to grade and the key to result', function () {
    submitForCompute();
    $dept = $this->course->department_id;
    // The department passes from 35 with its own letters; the university key stays for everyone else.
    GradeBand::create(['department_id' => $dept, 'faculty_id' => $this->course->department->faculty_id, 'letter' => 'P', 'min_score' => 35, 'max_score' => 100, 'points' => 4, 'rank' => 'Pass', 'is_pass' => true]);
    GradeBand::create(['department_id' => $dept, 'faculty_id' => $this->course->department->faculty_id, 'letter' => 'X', 'min_score' => 0, 'max_score' => 34.99, 'points' => 0, 'rank' => 'Fail', 'is_pass' => false]);
    ResultKey::create(['code' => 'PASS', 'min_gpa' => 1.5, 'max_gpa' => 5, 'rank' => 'Good standing', 'action' => 'proceed']);
    ResultKey::create(['code' => 'TOS', 'min_gpa' => 0, 'max_gpa' => 1.49, 'rank' => 'Probation', 'action' => 'probation', 'activity' => 'See the course adviser']);

    $this->actingAs($this->hod)->post(route('results.compute.run'), computeQuery())->assertSessionHas('success')->assertRedirect();

    [$tola, $bisi] = $this->students;
    expect(CourseResult::where('student_id', $tola->id)->value('grade'))->toBe('P')
        ->and(CourseResult::where('student_id', $bisi->id)->value('grade'))->toBe('X');
    $t = ComputedResult::where('student_id', $tola->id)->first();
    $b = ComputedResult::where('student_id', $bisi->id)->first();
    expect($t->sem_remark)->toBe('PASS')->and($t->cum_remark)->toBe('PASS')->and($t->present_tnu)->toBe(3)->and($t->present_tcp)->toBe(12.0)->and($t->present_gpa)->toBe(4.0)
        ->and($t->computed_by)->toBe($this->hod->id)
        ->and($b->sem_remark)->toBe('REP')->and($b->cum_remark)->toBe('TOS')->and($b->rank)->toBe('Probation')
        ->and($b->outstanding)->toBe([['code' => 'BIO 101', 'units' => 3, 'reason' => 'R']])->and($b->units_outstanding)->toBe(3);

    // The university key still grades another department's results.
    expect(\App\Support\GradeScale::grade(75)['grade'])->not->toBe('P');

    $this->get(route('results.compute', computeQuery()))->assertSee('Computed results')->assertSee('TOS')->assertSee('Master sheet (Excel)');
});

it('prints the department master marks sheet as Excel, PDF and a print page', function () {
    submitForCompute();
    $this->actingAs($this->hod)->get(route('results.compute.sheet', ['format' => 'excel'] + computeQuery()))->assertRedirect()->assertSessionHas('error');

    \App\Support\Institution::put('name', 'Adeyemi Federal University of Education');
    ResultKey::create(['code' => 'CSO', 'min_gpa' => 0, 'max_gpa' => 5, 'rank' => 'Carry some over', 'action' => 'carry_over']);
    $this->post(route('results.compute.run'), computeQuery());

    $print = $this->get(route('results.compute.sheet', ['format' => 'print'] + computeQuery()))->assertOk();
    $print->assertSee('MASTER MARKS SHEET')->assertSee('B.Ed. Biology - 100 Level (Non-Graduating)')->assertSee('2026/0001')
        ->assertSee('Used semester ratio: 1')->assertSee('Key to result')->assertSee('Carry some over');

    $xlsx = $this->get(route('results.compute.sheet', ['format' => 'excel'] + computeQuery()))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'ms') . '.xlsx';
    file_put_contents($path, $xlsx->getContent());
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    expect($xml)->toContain('REG NO')->toContain('CURRENT SEMESTER')->toContain('Adeyemi Federal University of Education');
    expect($xml)->toContain('2026/0001')->toContain('BIO 101')->toContain('75A')->toContain('mergeCell');

    if (\App\Services\Results\DepartmentMasterSheet::pdfAvailable()) {
        $pdf = $this->get(route('results.compute.sheet', ['format' => 'pdf'] + computeQuery()))->assertOk();
        expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
    }
});

it('keeps keys of different schools, departments and categories apart', function () {
    $this->actingAs($this->admin);
    $dept = $this->course->department_id;
    $row = fn ($extra) => $extra + ['letter' => 'A', 'min_score' => 70, 'max_score' => 100, 'points' => 5, 'rank' => 'Distinction', 'is_pass' => 1];

    // Overlaps the university's A band: refused. The same range for one department: fine.
    $this->post(route('academics.grade-bands.store'), $row([]))->assertSessionHasErrors('max_score');
    $this->post(route('academics.grade-bands.store'), $row(['department_id' => $dept]))->assertSessionHasNoErrors();
    $band = GradeBand::where('department_id', $dept)->first();
    expect($band->faculty_id)->toBe($this->course->department->faculty_id)->and($band->rank)->toBe('Distinction');

    $this->get(route('academics.grade-bands.index'))->assertOk()->assertSee('Key to Grade')->assertSee('Science Education')->assertSee('Whole university');
    $this->post(route('academics.result-keys.store'), ['code' => 'PASS', 'min_gpa' => 1, 'max_gpa' => 5, 'rank' => 'Good standing', 'action' => 'proceed'])->assertSessionHasNoErrors();
    $this->post(route('academics.result-keys.store'), ['code' => 'TOS', 'min_gpa' => 0, 'max_gpa' => 1.2, 'rank' => 'Probation', 'action' => 'probation'])->assertSessionHasErrors('max_gpa');
    $this->get(route('academics.result-keys.index'))->assertOk()->assertSee('Key to Result')->assertSee('Good standing');
    $this->get(route('results.settings'))->assertSee('Key to Grade')->assertSee('Key to Result');
});
