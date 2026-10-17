<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Programme;
use App\Models\RegistrationForm;
use App\Models\ScopedRole;
use App\Models\SemesterType;
use App\Models\StaffProfile;
use App\Models\Unit;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use App\Services\AdmissionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $person = fn ($name) => User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@example.com', 'password' => 'secret-pass']);
    $this->hod = $person('Hod Bola');
    $this->dean = $person('Dean Ade');
    $this->other = $person('Other Hod');
    $this->staffA = $person('Staff Alpha');
    $this->staffB = $person('Staff Beta');
    $this->worker = $person('Works Officer');

    $this->faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $science = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $this->deptA = Department::create(['faculty_id' => $this->faculty->id, 'name' => 'Science Education', 'code' => 'SED', 'hod_id' => $this->hod->id]);
    $this->deptB = Department::create(['faculty_id' => $science->id, 'name' => 'Chemistry', 'code' => 'CHM']);
    $this->unit = Unit::create(['name' => 'Works Department', 'code' => 'WRK']);
    StaffProfile::create(['user_id' => $this->hod->id, 'department_id' => $this->deptA->id, 'staff_number' => 'SP/001']);
    StaffProfile::create(['user_id' => $this->staffA->id, 'department_id' => $this->deptA->id, 'staff_number' => 'SP/002']);
    StaffProfile::create(['user_id' => $this->staffB->id, 'department_id' => $this->deptB->id, 'staff_number' => 'SP/003']);
    StaffProfile::create(['user_id' => $this->worker->id, 'unit_id' => $this->unit->id, 'staff_number' => 'SP/004']);

    $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    $admit = function (Department $d, string $first, string $jamb) use ($session) {
        $p = Programme::create(['department_id' => $d->id, 'name' => "B.Sc. {$d->name}", 'code' => $d->code . '-BSC', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
        $a = Applicant::create(['first_name' => $first, 'last_name' => 'Student', 'email' => strtolower($first) . '@example.com', 'jamb_reg_no' => $jamb, 'jamb_score' => 200,
            'programme_id' => $p->id, 'academic_session_id' => $session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'status' => 'pending', 'password' => 'my-secret-pw']);

        return app(AdmissionService::class)->admit($a->fresh())->student;
    };
    $this->studentA = $admit($this->deptA, 'Ada', '11111111AA');
    $this->studentB = $admit($this->deptB, 'Bayo', '22222222BB');
    $type = SemesterType::orderBy('id')->first();
    $this->formA = RegistrationForm::create(['student_id' => $this->studentA->id, 'academic_session_id' => $session->id, 'semester_type_id' => $type->id, 'level' => 100, 'status' => 'submitted', 'submitted_at' => now()]);
    $this->formB = RegistrationForm::create(['student_id' => $this->studentB->id, 'academic_session_id' => $session->id, 'semester_type_id' => $type->id, 'level' => 100, 'status' => 'submitted', 'submitted_at' => now()]);
});

it('gives a head of department the head role for their department only, and moves it with the headship', function () {
    $row = ScopedRole::where('user_id', $this->hod->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->role->name)->toBe('Head of Department')
        ->and([$row->scope_type, $row->scope_id, $row->source])->toBe(['department', $this->deptA->id, 'head'])
        ->and($this->hod->hasRole('Head of Department'))->toBeFalse();   // never a university-wide role

    $this->deptA->update(['hod_id' => $this->other->id]);
    expect(ScopedRole::where('user_id', $this->hod->id)->exists())->toBeFalse()
        ->and(ScopedRole::where(['user_id' => $this->other->id, 'scope_type' => 'department', 'scope_id' => $this->deptA->id])->exists())->toBeTrue();

    $empty = Department::create(['faculty_id' => $this->faculty->id, 'name' => 'Arts Education', 'code' => 'AED', 'hod_id' => $this->hod->id]);
    expect(ScopedRole::where(['scope_type' => 'department', 'scope_id' => $empty->id])->exists())->toBeTrue();
    $empty->delete();
    expect(ScopedRole::where(['scope_type' => 'department', 'scope_id' => $empty->id])->exists())->toBeFalse();
});

it('shows a head only the students, transcripts and staff of their own department', function () {
    $this->actingAs($this->hod);
    $this->get(route('students.index'))->assertOk()->assertSee('Ada')->assertDontSee('Bayo');
    $this->get(route('students.show', $this->studentA->id))->assertOk();
    $this->get(route('students.show', $this->studentB->id))->assertForbidden();
    $this->get(route('students.transcript', $this->studentA->id))->assertOk();
    $this->get(route('students.transcript', $this->studentB->id))->assertForbidden();
    $this->put(route('students.update', $this->studentA->id), [])->assertForbidden();   // seeing is not editing

    $this->get(route('staff.index'))->assertOk()->assertSee('Staff Alpha')->assertDontSee('Staff Beta')->assertDontSee('Works Officer');
    $a = StaffProfile::where('user_id', $this->staffA->id)->first();
    $this->get(route('staff.show', $a))->assertOk()->assertDontSee('Pay, pension and tax');
    $this->get(route('staff.show', StaffProfile::where('user_id', $this->staffB->id)->first()))->assertForbidden();
    $this->put(route('staff.pay', $a), ['bank_account_number' => '0123456789'])->assertForbidden();
    $this->get(route('payroll.runs.index'))->assertForbidden();
});

it('lets a head approve only their department\'s course registrations', function () {
    $this->actingAs($this->hod);
    $this->get(route('registrations.index'))->assertOk()->assertSee('Ada')->assertDontSee('Bayo');
    $this->get(route('registrations.show', $this->formB))->assertForbidden();
    $this->post(route('registrations.bulk'), ['forms' => [$this->formA->id, $this->formB->id]])->assertSessionHas('success');
    expect($this->formA->fresh()->status)->toBe('approved')->and($this->formB->fresh()->status)->toBe('submitted');
});

it('gives a dean the whole faculty but not the power to approve registrations', function () {
    $this->faculty->update(['dean_id' => $this->dean->id]);
    $dept2 = Department::create(['faculty_id' => $this->faculty->id, 'name' => 'Arts Education', 'code' => 'AED']);
    $scope = app(ScopedAccess::class)->scope($this->dean, 'View students');
    expect($scope->departmentIds)->toEqualCanonicalizing([$this->deptA->id, $dept2->id]);

    $this->actingAs($this->dean);
    $this->get(route('students.index'))->assertOk()->assertSee('Ada')->assertDontSee('Bayo');
    $this->get(route('registrations.show', $this->formA))->assertOk()->assertDontSee('Approve</button>', false);
    $this->post(route('registrations.decide', $this->formA), ['decision' => 'approve'])->assertForbidden();
});

it('limits the leave register to the head\'s staff and lets them open those applications', function () {
    $type = LeaveType::where('code', 'annual')->first();
    $mk = fn (User $u, string $ref) => LeaveRequest::create(['reference' => $ref, 'user_id' => $u->id, 'leave_type_id' => $type->id, 'year' => 2027, 'start_date' => '2027-03-15',
        'end_date' => '2027-03-19', 'days' => 5, 'expected_resumption' => '2027-03-22', 'reason' => 'Rest', 'status' => 'pending', 'submitted_at' => now()]);
    $a = $mk($this->staffA, 'LV/A/1');
    $b = $mk($this->staffB, 'LV/B/1');

    $this->actingAs($this->hod);
    $this->get(route('leave.register'))->assertOk()->assertSee('LV/A/1')->assertDontSee('LV/B/1');
    $this->get(route('leave.show', $a))->assertOk();
    $this->get(route('leave.show', $b))->assertForbidden();
    $this->actingAs($this->staffA)->get(route('leave.register'))->assertForbidden();
});

it('lets an administrator give a scoped role by hand, but not remove one that comes with a headship', function () {
    $this->actingAs($this->admin);
    $role = Role::where('name', 'Head of Unit')->first();
    $this->post(route('scoped-roles.store'), ['user_id' => $this->staffA->id, 'role_id' => $role->id, 'scope' => "unit:{$this->unit->id}"])->assertSessionHas('success');
    $this->get(route('scoped-roles.index'))->assertOk()->assertSee('Works Department');

    $this->actingAs($this->staffA)->get(route('staff.index'))->assertOk()->assertSee('Works Officer')->assertDontSee('Staff Beta');

    $this->actingAs($this->admin);
    $head = ScopedRole::where('source', 'head')->first();
    $this->delete(route('scoped-roles.destroy', $head))->assertSessionHas('error');
    $manual = ScopedRole::where('source', 'manual')->first();
    $this->delete(route('scoped-roles.destroy', $manual))->assertSessionHas('success');
    $this->actingAs($this->staffA->fresh())->get(route('staff.index'))->assertForbidden();
});

it('lets the head roles be renamed, and keeps people with no role out', function () {
    $this->actingAs($this->worker)->get(route('students.index'))->assertForbidden();
    $this->actingAs($this->worker)->get(route('staff.index'))->assertForbidden();

    Role::create(['name' => 'Department Head', 'guard_name' => 'web'])->givePermissionTo('View students');
    $this->actingAs($this->admin)->put(route('scoped-roles.settings'), ['department' => 'Department Head', 'faculty' => 'Dean', 'unit' => 'Head of Unit'])->assertSessionHas('success');
    expect(ScopedRole::where('user_id', $this->hod->id)->first()->role->name)->toBe('Department Head');
    $this->actingAs($this->hod)->get(route('staff.index'))->assertForbidden();
    $this->actingAs($this->hod)->get(route('students.index'))->assertOk();
});

it('lets a head search only the students and staff of their own department', function () {
    $hits = $this->actingAs($this->hod)->getJson(route('search', ['q' => 'Student']))->assertOk()->json('results');
    expect(collect($hits)->where('category', 'Students')->pluck('title')->all())->toBe([$this->studentA->full_name]);

    $staff = collect($this->getJson(route('search', ['q' => 'SP/00']))->json('results'))->where('category', 'Staff')->pluck('title')->sort()->values()->all();
    expect($staff)->toBe(['Hod Bola', 'Staff Alpha']);
});

it('lets a head of department define only their own department\'s courses for their own staff', function () {
    $type = \App\Models\SemesterType::orderBy('sort_order')->first();
    $session = AcademicSession::where('is_current', true)->first();
    $mine = \App\Models\Course::create(['department_id' => $this->deptA->id, 'code' => 'SED101', 'title' => 'Intro to Science Education', 'credit_units' => 2, 'level' => 100]);
    $theirs = \App\Models\Course::create(['department_id' => $this->deptB->id, 'code' => 'CHM101', 'title' => 'General Chemistry', 'credit_units' => 3, 'level' => 100]);
    \App\Models\CourseAssignment::create(['user_id' => $this->staffB->id, 'course_id' => $theirs->id, 'academic_session_id' => $session->id, 'semester_type_id' => $type->id]);
    $row = fn ($user, $course) => ['user_id' => $user->id, 'course_id' => $course->id, 'academic_session_id' => $session->id, 'semester_type_id' => $type->id];

    $this->actingAs($this->hod)->get(route('academics.course-assignments.index'))->assertOk()
        ->assertSee('Define Courses')->assertSee('SED101')->assertDontSee('CHM101 - General Chemistry')->assertDontSee('Staff Beta');

    $this->post(route('academics.course-assignments.store'), $row($this->staffA, $mine))->assertSessionHasNoErrors();
    expect(\App\Models\CourseAssignment::where('user_id', $this->staffA->id)->where('course_id', $mine->id)->exists())->toBeTrue();

    $this->post(route('academics.course-assignments.store'), $row($this->staffB, $mine))->assertSessionHasErrors('user_id');
    $this->post(route('academics.course-assignments.store'), $row($this->staffA, $theirs))->assertSessionHasErrors('course_id');
    $other = \App\Models\CourseAssignment::where('course_id', $theirs->id)->first();
    $this->delete(route('academics.course-assignments.destroy', $other->id))->assertForbidden();

    // Ordinary staff of the department cannot define courses at all.
    $this->actingAs($this->staffA)->get(route('academics.course-assignments.index'))->assertForbidden();
});

it('lists only the head\'s own department on Define Courses, even when they may view every assignment', function () {
    $type = \App\Models\SemesterType::orderBy('sort_order')->first();
    $session = AcademicSession::where('is_current', true)->first();
    $theirs = \App\Models\Course::create(['department_id' => $this->deptB->id, 'code' => 'CHM101', 'title' => 'General Chemistry', 'credit_units' => 3, 'level' => 100]);
    \App\Models\CourseAssignment::create(['user_id' => $this->staffB->id, 'course_id' => $theirs->id, 'academic_session_id' => $session->id, 'semester_type_id' => $type->id]);
    $this->hod->givePermissionTo('View course assignments');

    $this->actingAs($this->hod)->get(route('academics.course-assignments.index'))->assertOk()->assertDontSee('CHM101 - General Chemistry');
});
