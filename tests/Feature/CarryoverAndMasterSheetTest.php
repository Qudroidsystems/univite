<?php

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

    $this->role = Role::create(['name' => 'Lecturer']);
    $this->lecturer = User::factory()->create();
    $this->lecturer->assignRole('Lecturer');
    CourseAssignment::create(['user_id' => $this->lecturer->id, 'course_id' => $this->course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]);
    $this->sheet = app(ResultService::class)->sheet($this->course->id, $this->session->id, $this->type->id);
});


function masterScores(array $map): array
{
    return collect($map)->mapWithKeys(fn ($v, $i) => [test()->students[$i]->id => ['ca' => $v[0], 'exam' => $v[1]]])->all();
}

function masterQuery(): array
{
    return ['programme_id' => test()->programme->id, 'level' => 100, 'session_id' => test()->session->id, 'semester_type_id' => test()->type->id];
}

/** Scores entered and submitted: Tola passes, Bisi fails. */
function submitSheet(): void
{
    test()->actingAs(test()->lecturer)->post(route('results.sheets.save', test()->sheet->id), ['scores' => masterScores([[25, 50], [10, 20]])]);
    test()->post(route('results.sheets.submit', test()->sheet->id));
}

function publishSheet2(): void
{
    submitSheet();
    test()->actingAs(test()->admin);
    foreach (['approve', 'approve', 'publish'] as $d) {
        test()->post(route('results.sheets.decide', test()->sheet->id), ['decision' => $d]);
    }
}

it('lets a head approve only sheets of their own department, at the step their role signs', function () {
    submitSheet();

    $this->actingAs($this->otherHod)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve'])->assertSessionHas('error');
    $this->actingAs($this->dean)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve'])->assertSessionHas('error');   // not the dean's step yet

    $this->actingAs($this->hod)->get(route('results.sheets.index'))->assertOk()->assertSee('Waiting for your approval')->assertSee('BIO 101');
    $this->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve'])->assertSessionHas('success');
    expect($this->sheet->fresh()->stage->name)->toBe('Dean of Faculty');

    $this->actingAs($this->dean)->post(route('results.sheets.decide', $this->sheet->id), ['decision' => 'approve'])->assertSessionHas('success');
    expect($this->sheet->fresh()->status)->toBe('approved');
});

it('shows the master sheet with results still going through approval, which students cannot see', function () {
    submitSheet();

    $this->actingAs($this->hod)->get(route('results.broadsheet', masterQuery()))->assertOk()
        ->assertSee('At Head of Department')->assertSee('75 A')->assertSee('30 F')->assertSee('Carry over BIO 101')->assertSee('Provisional')
        ->assertSee('TCP')->assertSee('TNU')->assertDontSee('TCR')->assertSee('Outstanding<br>results', false);
    $published = $this->get(route('results.broadsheet', masterQuery() + ['published_only' => 1]))->assertOk()->assertDontSee('75 A')->assertSee('uv-more', false)->assertSee('>AR<', false);
    expect($published->viewData('sheet')->rows->first()->outstanding->all())->toContain('BIO 101');
    expect($published->viewData('sheet')->rows->first()->mark($this->course->id))->toBe('AR')
        ->and($published->viewData('sheet')->rows->first()->mark($this->course2->id))->toBe('NR');

    $this->actingAs($this->otherHod)->get(route('results.broadsheet', masterQuery()))->assertForbidden();
    $this->actingAs($this->students[0]->user)->get(route('results.broadsheet', masterQuery()))->assertForbidden();
});

it('approves and publishes a whole level from the master sheet', function () {
    submitSheet();

    $this->actingAs($this->hod)->get(route('results.broadsheet', masterQuery()))->assertSee('Approve all 1');
    $this->post(route('results.broadsheet.decide', masterQuery()), ['decision' => 'approve', 'note' => 'Departmental board'])->assertSessionHas('success');
    expect($this->sheet->fresh()->stage->name)->toBe('Dean of Faculty');
    $this->post(route('results.broadsheet.decide', masterQuery()), ['decision' => 'approve'])->assertSessionHas('error');   // nothing left for the HOD

    $this->actingAs($this->dean)->post(route('results.broadsheet.decide', masterQuery()), ['decision' => 'approve'])->assertSessionHas('success');
    expect($this->sheet->fresh()->status)->toBe('approved');

    $this->actingAs($this->dean)->post(route('results.broadsheet.decide', masterQuery()), ['decision' => 'publish'])->assertSessionHas('error');
    $this->actingAs($this->admin)->post(route('results.broadsheet.decide', masterQuery()), ['decision' => 'publish'])->assertSessionHas('success');
    expect($this->sheet->fresh()->status)->toBe('published')->and(CourseResult::where('status', 'published')->count())->toBe(2);
    expect($this->sheet->fresh()->events()->where('note', 'like', '%Departmental board%')->exists())->toBeTrue();
});

it('downloads the master sheet as Excel and opens a print layout', function () {
    publishSheet2();

    $res = $this->actingAs($this->admin)->get(route('results.broadsheet.excel', masterQuery()))->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and($res->headers->get('Content-Disposition'))->toContain('.xlsx');
    $tmp = tempnam(sys_get_temp_dir(), 'ms') . '.xlsx';
    file_put_contents($tmp, $res->getContent());
    $rows = \App\Support\Xlsx::read($tmp);
    expect($rows[0])->toContain('BIO 101 (3) C')->toContain('CGPA')->toContain('Remark')
        ->and(collect($rows)->flatten()->implode('|'))->toContain('2026/0002')->toContain('Carry over BIO 101');

    $this->get(route('results.broadsheet.print', masterQuery()))->assertOk()->assertSee('window.print()', false)->assertSee('Chairman, Senate');
});

it('lets the head of department waive a carry-over, and withdraw the waiver', function () {
    publishSheet2();
    $bisi = $this->students[1];
    $registration = app(RegistrationService::class);
    expect($registration->failed($bisi)->all())->toBe([$this->course->id]);

    $this->actingAs($this->otherHod)->post(route('results.waivers.store'), ['student_id' => $bisi->id, 'course_id' => $this->course->id, 'reason' => 'Course withdrawn from curriculum'])->assertSessionHas('error');
    $this->actingAs($this->hod)->post(route('results.waivers.store'), ['student_id' => $this->students[0]->id, 'course_id' => $this->course->id, 'reason' => 'Not needed'])->assertSessionHas('error');   // she passed it
    $this->post(route('results.waivers.store'), ['student_id' => $bisi->id, 'course_id' => $this->course->id, 'reason' => 'Course withdrawn from curriculum'])->assertSessionHas('success');

    expect($registration->failed($bisi->fresh())->all())->toBe([])
        ->and($bisi->user->fresh()->notifications()->where('data->title', 'Carry-over waived')->exists())->toBeTrue();
    $this->get(route('results.broadsheet', masterQuery()))->assertSee('Waived: BIO 101')->assertDontSee('Carry over BIO 101');
    $this->get(route('results.summary', ['department_id' => $this->programme->department_id, 'session_id' => $this->session->id, 'semester_type_id' => $this->type->id]))
        ->assertOk()->assertSee('BIO 101 waived');
    $this->get(route('results.waivers.index'))->assertOk()->assertSee('Course withdrawn from curriculum');
    // the failed grade still counts in the CGPA
    expect(app(ResultService::class)->transcript($bisi)->cgpa)->toBe(0.0);

    $this->post(route('results.waivers.store'), ['student_id' => $bisi->id, 'course_id' => $this->course->id, 'reason' => 'Again please'])->assertSessionHas('error');   // already waived
    $waiver = CarryoverWaiver::firstOrFail();
    $this->post(route('results.waivers.withdraw', $waiver->id), ['reason' => 'Senate did not agree'])->assertSessionHas('success');
    expect($waiver->fresh()->isActive())->toBeFalse()->and($registration->failed($bisi->fresh())->all())->toBe([$this->course->id]);
});
