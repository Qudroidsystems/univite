<?php

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\ScopedRole;
use App\Models\SemesterType;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use App\Services\ResultService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $this->dept = Department::create(['faculty_id' => $this->faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $this->other = Department::create(['faculty_id' => $this->faculty->id, 'name' => 'Arts Education', 'code' => 'AED']);
});

it('seeds the office roles with their permissions, and keeps a permission an administrator took away', function () {
    foreach (array_keys(RoleSeeder::ROLES) as $name) {
        expect(Role::where('name', $name)->exists())->toBeTrue();
    }
    expect(Role::findByName('Head of Department')->hasPermissionTo('Approve results'))->toBeTrue()
        ->and(Role::findByName('Lecturer')->hasPermissionTo('Enter results for assigned courses'))->toBeTrue()
        ->and(Role::findByName('Senate')->hasPermissionTo('Publish results'))->toBeTrue()
        ->and(Role::findByName('Bursar')->hasPermissionTo('View invoices'))->toBeTrue();

    Role::findByName('Dean')->revokePermissionTo('Approve results');
    $this->seed(RoleSeeder::class);
    expect(Role::findByName('Dean')->fresh()->hasPermissionTo('Approve results'))->toBeFalse();
});

it('lets someone enter scores for a defined course only through the permission, which defining the course gives', function () {
    $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    $type = SemesterType::where('sort_order', 1)->first();
    $course = Course::create(['department_id' => $this->dept->id, 'code' => 'BIO 101', 'title' => 'Intro Biology', 'credit_units' => 3]);
    $lecturer = User::factory()->create();

    CourseAssignment::create(['user_id' => $lecturer->id, 'course_id' => $course->id, 'academic_session_id' => $session->id, 'semester_type_id' => $type->id]);
    expect($lecturer->fresh()->hasRole('Lecturer'))->toBeTrue();
    $sheet = app(ResultService::class)->sheet($course->id, $session->id, $type->id);
    expect(app(ResultService::class)->canEnter($lecturer->fresh(), $sheet))->toBeTrue();

    Role::findByName('Lecturer')->revokePermissionTo('Enter results for assigned courses');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    expect(app(ResultService::class)->canEnter($lecturer->fresh(), $sheet))->toBeFalse();
    $this->actingAs($lecturer->fresh())->get(route('results.sheets.show', $sheet->id))->assertForbidden();
});

it('decides who edits a department\'s academic rules by the role held there, not by who is named head', function () {
    $user = User::factory()->create();
    // Not the head of department, but given the head role for the department on Scoped Roles & Heads.
    ScopedRole::create(['user_id' => $user->id, 'role_id' => Role::findByName('Head of Department')->id, 'scope_type' => 'department', 'scope_id' => $this->dept->id]);
    app(ScopedAccess::class)->forget();

    $this->actingAs($user)->put(route('academics.rules.update'), ['scope' => 'department', 'id' => $this->dept->id, 'rules' => ['max_units' => 20]])->assertSessionHas('success');
    $this->put(route('academics.rules.update'), ['scope' => 'department', 'id' => $this->other->id, 'rules' => ['max_units' => 20]])->assertForbidden();

    // Named head, but the head role no longer carries the permission: no access.
    Role::findByName('Head of Department')->revokePermissionTo('Edit department academic rules');
    $head = User::factory()->create();
    $this->other->update(['hod_id' => $head->id]);
    app(ScopedAccess::class)->forget();
    $this->actingAs($head)->put(route('academics.rules.update'), ['scope' => 'department', 'id' => $this->other->id, 'rules' => ['max_units' => 20]])->assertForbidden();
});
