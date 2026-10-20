<?php

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseResult;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\ResultSheet;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\User;
use App\Support\AcademicRules;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $f = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $this->dept = Department::create(['faculty_id' => $f->id, 'name' => 'Chemistry', 'code' => 'CHM']);
    $this->prog = Programme::create(['department_id' => $this->dept->id, 'name' => 'B.Sc. Chemistry', 'code' => 'BSC-CHM', 'degree_type' => 'B.Sc', 'duration_years' => 5]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $this->type = SemesterType::where('sort_order', 1)->first();
});

it('sets admission, graduating and Senate levels per programme under Academic Rules', function () {
    $rules = app(AcademicRules::class);
    expect($rules->graduatingLevel($this->prog))->toBe(500)->and($rules->admissionLevels($this->prog))->toBe([100])->and($rules->senateLevels($this->prog))->toBe([500]);

    $this->actingAs($this->admin)->put(route('academics.rules.update'), ['scope' => 'programme', 'id' => $this->prog->id, 'rules' => [
        'admission_level' => 100, 'direct_entry_levels' => '200, 300', 'graduating_level' => 400, 'senate_levels' => '400,300',
    ]])->assertSessionHas('success');
    $rules->flush();
    expect($rules->graduatingLevel($this->prog))->toBe(400)->and($rules->admissionLevels($this->prog))->toBe([100, 200, 300])->and($rules->senateLevels($this->prog))->toBe([300, 400]);

    $this->put(route('academics.rules.update'), ['scope' => 'programme', 'id' => $this->prog->id, 'rules' => ['senate_levels' => '250']])->assertSessionHasErrors('rules.senate_levels');
    $this->put(route('academics.rules.update'), ['scope' => 'programme', 'id' => $this->prog->id, 'rules' => ['graduating_level' => 600]])->assertSessionHasErrors('rules.graduating_level');
    $this->get(route('academics.rules.index', ['scope' => 'programme', 'id' => $this->prog->id]))->assertOk()->assertSee('Levels whose results Senate approves');
    $this->get(route('academics.programmes.index'))->assertOk()->assertSee('Senate:');

    // only the admission levels are offered to applicants once they are set
    $this->post(route('applicants.store'), ['first_name' => 'Ola', 'last_name' => 'Ade', 'email' => 'ola@example.com', 'programme_id' => $this->prog->id,
        'academic_session_id' => $this->session->id, 'entry_mode' => 'direct_entry', 'entry_level' => 400])->assertSessionHasErrors('entry_level');

    // the graduation list uses the graduating level
    expect(app(\App\Services\GraduationService::class)->finalLevel($this->prog))->toBe(400);
});

it('makes a returning student register outstanding courses first and keeps them in view', function () {
    $c100 = Course::create(['department_id' => $this->dept->id, 'code' => 'CHM 101', 'title' => 'General Chemistry', 'credit_units' => 3, 'level' => 100]);
    $c200 = Course::create(['department_id' => $this->dept->id, 'code' => 'CHM 201', 'title' => 'Organic I', 'credit_units' => 3, 'level' => 200]);
    foreach ([[$c100, 100], [$c200, 200]] as [$c, $l]) {
        ProgrammeCourse::create(['programme_id' => $this->prog->id, 'course_id' => $c->id, 'level' => $l, 'semester_order' => 1, 'status' => 'compulsory']);
    }
    $student = makeStudent($this, 'Uche', 'male', 200, $this->prog);
    $old = AcademicSession::create(['name' => '2025/2026']);
    $sheet = ResultSheet::create(['course_id' => $c100->id, 'academic_session_id' => $old->id, 'semester_type_id' => $this->type->id, 'status' => 'published']);
    CourseResult::create(['result_sheet_id' => $sheet->id, 'student_id' => $student->id, 'course_id' => $c100->id, 'academic_session_id' => $old->id, 'semester_type_id' => $this->type->id,
        'ca' => 10, 'exam' => 15, 'total' => 25, 'grade' => 'F', 'points' => 0, 'is_pass' => false, 'credit_units' => 3, 'status' => 'published']);
    \App\Models\Setting::put('registration.min_units', '0');

    $this->actingAs($student->user)->get(route('my.registration'))->assertOk()->assertSee('outstanding course')->assertSee('Outstanding (carry-over) courses')
        ->assertSee('data-required="1"', false)->assertSee('Last attempt 2025/2026');
    $this->post(route('my.registration.save'), ['courses' => [$c200->id]])->assertSessionHas('error', fn ($m) => str_contains($m, 'Carry-over courses must be registered: CHM 101'));
    $this->post(route('my.registration.save'), ['courses' => [$c100->id, $c200->id]])->assertSessionHas('success');
    $this->get(route('dashboard'))->assertOk()->assertSee('Outstanding courses')->assertSee('CHM 101')->assertSee('My Portal');

    $token = $student->user->createToken('phone')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/outstanding')->assertOk()->assertJsonPath('data.0.code', 'CHM 101')->assertJsonPath('data.0.registered_now', true)
        ->assertJsonPath('data.0.owner.department.name', 'Chemistry')->assertJsonPath('meta.must_register_first', true);
    $this->withToken($token)->getJson('/api/v1/registration')->assertOk()->assertJsonPath('data.carryovers_first', true)
        ->assertJsonPath('data.offered.0.must_register_first', true)->assertJsonPath('data.offered.0.owner.faculty.name', 'Faculty of Science');
    $this->withToken($token)->getJson('/api/v1/planner/gpa')->assertOk()->assertJsonPath('data.outstanding.0.code', 'CHM 101');
});

it('shows the head of department who approves a course and the approval steps in the app', function () {
    $hod = makeStaff($this, 'Dr Bello Musa', $this->dept);
    $this->dept->update(['hod_id' => $hod->id]);
    $steps = \App\Models\ResultStage::chain()->pluck('name');
    $c = Course::create(['department_id' => $this->dept->id, 'code' => 'CHM 301', 'title' => 'Physical Chemistry', 'credit_units' => 3, 'level' => 300]);
    $lecturer = makeStaff($this, 'Mrs Ada Obi', $this->dept);
    \App\Models\CourseAssignment::create(['user_id' => $lecturer->id, 'course_id' => $c->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]);
    app(\App\Services\Access\LecturerRole::class)->ensure($lecturer);

    $token = $lecturer->createToken('phone')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/teaching/courses')->assertOk()
        ->assertJsonPath('data.courses.0.course.owner.department.name', 'Chemistry')
        ->assertJsonPath('data.courses.0.course.owner.hod.name', $hod->fresh()->staffProfile->displayName())
        ->assertJsonPath('data.courses.0.course.approval.0.name', $steps[0])->assertJsonCount($steps->count(), 'data.courses.0.course.approval');
    $sheet = $this->withToken($token)->postJson("/api/v1/teaching/courses/{$c->id}/open")->assertOk()->json('data');
    expect($sheet['approval_steps'][0])->toMatchArray(['name' => $steps[0], 'state' => 'to_come']);
});

it('shows the menu as cards on the staff dashboard', function () {
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('uv-menu-card', false)->assertSee('Academic Setup')->assertSee('Senate Approval')->assertSee('School Loans');
});
