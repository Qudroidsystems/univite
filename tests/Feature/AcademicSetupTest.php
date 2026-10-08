<?php

use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\Semester;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
});

function makeStructure(): array
{
    $faculty = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $dept    = Department::create(['faculty_id' => $faculty->id, 'name' => 'Computer Science', 'code' => 'CSC']);
    $prog    = Programme::create(['department_id' => $dept->id, 'name' => 'B.Sc. Computer Science', 'code' => 'BSC-CSC', 'degree_type' => 'B.Sc', 'duration_years' => 4]);

    return [$faculty, $dept, $prog];
}

it('shows the login page and redirects guests away from the portal', function () {
    $this->get('/login')->assertOk()->assertSee('Sign in');
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/academics/sessions')->assertRedirect('/login');
});

it('does not offer public registration', function () {
    $this->get('/register')->assertNotFound();
});

it('lets an admin log in', function () {
    $this->post('/login', ['email' => $this->admin->email, 'password' => env('ADMIN_PASSWORD', '12345678')])
        ->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($this->admin);
});

it('blocks disabled accounts at login', function () {
    $this->admin->update(['is_disabled' => true]);
    $this->post('/login', ['email' => $this->admin->email, 'password' => env('ADMIN_PASSWORD', '12345678')])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('renders every setup page for a super admin', function (string $route) {
    makeStructure();
    $this->actingAs($this->admin)->get(route($route))->assertOk();
})->with([
    'dashboard', 'users.index', 'roles.index', 'permissions.index',
    'academics.sessions.index', 'academics.semesters.index', 'academics.faculties.index',
    'academics.departments.index', 'academics.programmes.index', 'academics.courses.index',
    'academics.curriculum.index', 'applicants.index', 'invoices.index', 'students.index', 'students.settings', 'bills.items.index', 'bills.structure.index',
]);

it('renders role and user detail pages', function () {
    $role = \Spatie\Permission\Models\Role::where('name', 'Admin')->first();
    $this->actingAs($this->admin)->get(route('roles.show', $role->id))->assertOk();
    $this->actingAs($this->admin)->get(route('users.show', $this->admin))->assertOk();
});

it('creates a session with semesters and makes it current', function () {
    $this->actingAs($this->admin)->post(route('academics.sessions.store'), [
        'name' => '2025/2026', 'make_current' => 1, 'create_semesters' => 1,
    ])->assertSessionHasNoErrors();

    $session = AcademicSession::where('name', '2025/2026')->first();
    expect($session->is_current)->toBeTrue()
        ->and($session->semesters)->toHaveCount(2)
        ->and($session->semesters->pluck('name')->all())->toBe(['First Semester', 'Second Semester']);

    // A second session takes over as current
    $this->actingAs($this->admin)->post(route('academics.sessions.store'), ['name' => '2026/2027', 'make_current' => 1]);
    expect(AcademicSession::current()->name)->toBe('2026/2027')
        ->and(AcademicSession::where('is_current', true)->count())->toBe(1);
});

it('rejects badly formatted or duplicate sessions', function () {
    $this->actingAs($this->admin)->post(route('academics.sessions.store'), ['name' => '2025-26'])->assertSessionHasErrors('name');
    AcademicSession::create(['name' => '2025/2026']);
    $this->actingAs($this->admin)->post(route('academics.sessions.store'), ['name' => '2025/2026'])->assertSessionHasErrors('name');
});

it('keeps exactly one current semester and moves the current session with it', function () {
    $a = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);
    $b = AcademicSession::create(['name' => '2026/2027']);
    $s1 = Semester::create(['academic_session_id' => $a->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $s2 = Semester::create(['academic_session_id' => $b->id, 'name' => 'First Semester', 'sort_order' => 1]);

    $this->actingAs($this->admin)->post(route('academics.semesters.make-current', $s2))->assertRedirect();

    expect(Semester::current()->id)->toBe($s2->id)
        ->and(Semester::where('is_current', true)->count())->toBe(1)
        ->and(AcademicSession::current()->id)->toBe($b->id)
        ->and($s1->fresh()->is_current)->toBeFalse();
});

it('does not allow the same semester name twice in one session', function () {
    $a = AcademicSession::create(['name' => '2025/2026']);
    Semester::create(['academic_session_id' => $a->id, 'name' => 'First Semester', 'sort_order' => 1]);

    $this->actingAs($this->admin)->post(route('academics.semesters.store'), [
        'academic_session_id' => $a->id, 'name' => 'First Semester', 'sort_order' => 2,
    ])->assertSessionHasErrors('name');
});

it('builds faculty, department, programme and course', function () {
    $this->actingAs($this->admin);

    $this->post(route('academics.faculties.store'), ['name' => 'Faculty of Science', 'code' => 'sci', 'is_active' => 1])->assertSessionHasNoErrors();
    $faculty = Faculty::first();
    expect($faculty->code)->toBe('SCI');

    $this->post(route('academics.departments.store'), ['faculty_id' => $faculty->id, 'name' => 'Computer Science', 'code' => 'csc'])->assertSessionHasNoErrors();
    $dept = Department::first();

    $this->post(route('academics.programmes.store'), [
        'department_id' => $dept->id, 'name' => 'B.Sc. Computer Science', 'code' => 'bsc-csc', 'degree_type' => 'B.Sc', 'study_category_id' => \App\Models\StudyCategory::firstOrCreate(['code' => 'DEG'], ['name' => 'Degree', 'duration_years' => 4])->id,
        'duration_years' => 4, 'min_credit_load' => 15, 'max_credit_load' => 24,
    ])->assertSessionHasNoErrors();
    $prog = Programme::first();
    expect($prog->levels())->toBe([100, 200, 300, 400]);

    $this->post(route('academics.courses.store'), [
        'department_id' => $dept->id, 'code' => ' csc   101 ', 'title' => 'Intro to Computing', 'credit_units' => 3, 'level' => 100,
    ])->assertSessionHasNoErrors();
    expect(Course::first()->code)->toBe('CSC 101')->and(Course::first()->credit_units)->toBe(3);
});

it('rejects a maximum credit load below the minimum', function () {
    [, $dept] = makeStructure();
    $this->actingAs($this->admin)->post(route('academics.programmes.store'), [
        'department_id' => $dept->id, 'name' => 'B.Sc. Physics', 'code' => 'PHY', 'degree_type' => 'B.Sc', 'study_category_id' => \App\Models\StudyCategory::create(['name' => 'Degree', 'code' => 'DEG', 'duration_years' => 4])->id,
        'duration_years' => 4, 'min_credit_load' => 20, 'max_credit_load' => 10,
    ])->assertSessionHasErrors('max_credit_load');
});

it('refuses to delete records that are in use instead of crashing', function () {
    [$faculty] = makeStructure();
    $this->actingAs($this->admin)->delete(route('academics.faculties.destroy', $faculty))
        ->assertRedirect()->assertSessionHas('error');
    expect(Faculty::count())->toBe(1);
});

it('edits an existing record without tripping the unique rules', function () {
    [$faculty] = makeStructure();
    $this->actingAs($this->admin)->put(route('academics.faculties.update', $faculty), ['name' => 'Faculty of Science', 'code' => 'SCI', 'description' => 'Updated'])
        ->assertSessionHasNoErrors();
    expect($faculty->fresh()->description)->toBe('Updated');
});

it('builds a curriculum and totals credit units', function () {
    [, $dept, $prog] = makeStructure();
    $c1 = Course::create(['department_id' => $dept->id, 'code' => 'CSC 101', 'title' => 'Intro', 'credit_units' => 3]);
    $c2 = Course::create(['department_id' => $dept->id, 'code' => 'CSC 102', 'title' => 'Programming', 'credit_units' => 4]);

    $this->actingAs($this->admin)->post(route('academics.curriculum.store'), [
        'programme_id' => $prog->id, 'course_ids' => [$c1->id, $c2->id], 'level' => 100, 'semester_order' => 1, 'status' => 'core',
    ])->assertSessionHasNoErrors();
    expect(ProgrammeCourse::count())->toBe(2);

    $this->get(route('academics.curriculum.index', ['programme' => $prog->id]))->assertOk()->assertSee('7 units');

    // The same course cannot be added twice, and a level the programme does not run is refused
    $this->post(route('academics.curriculum.store'), ['programme_id' => $prog->id, 'course_ids' => [$c1->id], 'level' => 100, 'semester_order' => 2, 'status' => 'core']);
    expect(ProgrammeCourse::count())->toBe(2);
    $this->post(route('academics.curriculum.store'), ['programme_id' => $prog->id, 'course_ids' => [$c1->id], 'level' => 500, 'semester_order' => 1, 'status' => 'core'])
        ->assertSessionHas('error');

    $entry = ProgrammeCourse::first();
    $this->put(route('academics.curriculum.update', $entry), ['level' => 200, 'semester_order' => 2, 'status' => 'elective'])->assertSessionHasNoErrors();
    expect($entry->fresh()->level)->toBe(200);
    $this->delete(route('academics.curriculum.destroy', $entry));
    expect(ProgrammeCourse::count())->toBe(1);
});

it('sets school bills per programme, level and session and blocks duplicates', function () {
    [, , $prog] = makeStructure();
    $session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);
    $item = BillItem::create(['name' => 'Tuition', 'code' => 'TUI']);

    $payload = ['academic_session_id' => $session->id, 'programme_id' => $prog->id, 'level' => 100, 'bill_item_id' => $item->id, 'student_type' => 'all', 'amount' => 150000];
    $this->actingAs($this->admin)->post(route('bills.structure.store'), $payload)->assertSessionHasNoErrors();
    expect((float) Bill::first()->amount)->toBe(150000.0);

    $this->post(route('bills.structure.store'), $payload)->assertSessionHasErrors('bill_item_id');
    $this->post(route('bills.structure.store'), array_merge($payload, ['level' => 500]))->assertSessionHasErrors('level');
    $this->post(route('bills.structure.store'), array_merge($payload, ['level' => 200, 'amount' => -5]))->assertSessionHasErrors('amount');

    $this->get(route('bills.structure.index'))->assertOk()->assertSee('150,000.00');
});

it('copies bills to a new session with a percentage change and never overwrites', function () {
    [, , $prog] = makeStructure();
    $from = AcademicSession::create(['name' => '2025/2026']);
    $to   = AcademicSession::create(['name' => '2026/2027']);
    $item = BillItem::create(['name' => 'Tuition', 'code' => 'TUI']);
    Bill::create(['academic_session_id' => $from->id, 'programme_id' => $prog->id, 'level' => 100, 'bill_item_id' => $item->id, 'amount' => 100000]);
    Bill::create(['academic_session_id' => $from->id, 'programme_id' => $prog->id, 'level' => 200, 'bill_item_id' => $item->id, 'amount' => 120000]);

    $this->actingAs($this->admin)->post(route('bills.copy'), ['from_session_id' => $from->id, 'to_session_id' => $to->id, 'percent' => 10])
        ->assertSessionHasNoErrors();
    expect(Bill::where('academic_session_id', $to->id)->count())->toBe(2)
        ->and((float) Bill::where('academic_session_id', $to->id)->where('level', 100)->value('amount'))->toBe(110000.0);

    // Running it again leaves the copied bills unchanged
    $this->post(route('bills.copy'), ['from_session_id' => $from->id, 'to_session_id' => $to->id, 'percent' => 50]);
    expect(Bill::where('academic_session_id', $to->id)->count())->toBe(2)
        ->and((float) Bill::where('academic_session_id', $to->id)->where('level', 100)->value('amount'))->toBe(110000.0);

    $this->post(route('bills.copy'), ['from_session_id' => $from->id, 'to_session_id' => $from->id])->assertSessionHasErrors('to_session_id');
});

it('enforces permissions on the setup pages', function () {
    $user = User::factory()->create();
    $user->givePermissionTo(Permission::findByName('View faculties'));

    $this->actingAs($user)->get(route('academics.faculties.index'))->assertOk();
    $this->actingAs($user)->post(route('academics.faculties.store'), ['name' => 'X', 'code' => 'X'])->assertForbidden();
    $this->actingAs($user)->get(route('academics.courses.index'))->assertForbidden();
    $this->actingAs($user)->get(route('bills.structure.index'))->assertForbidden();
    $this->actingAs($user)->post(route('academics.sessions.make-current', 1))->assertForbidden();

    // The sidebar only shows what the user may open
    $this->actingAs($user)->get(route('academics.faculties.index'))->assertSee('Faculties')->assertDontSee('School Bills');
});

it('creates users with a generated password and protects Super Admin', function () {
    $this->actingAs($this->admin)->post(route('users.store'), ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'roles' => ['Admin']])
        ->assertSessionHasNoErrors()->assertSessionHas('generated_password');

    $jane = User::where('email', 'jane@example.com')->first();
    expect($jane->hasRole('Admin'))->toBeTrue();

    // Self-delete and last-Super-Admin delete are refused
    $this->delete(route('users.destroy', $this->admin))->assertSessionHas('error');
    expect(User::find($this->admin->id))->not->toBeNull();

    // A non-Super-Admin cannot hand out the Super Admin role
    $jane->givePermissionTo(['View users', 'Add users', 'Edit users']);
    $this->actingAs($jane)->post(route('users.store'), ['name' => 'Eve', 'email' => 'eve@example.com', 'roles' => ['Super Admin']])
        ->assertSessionHasErrors('roles.0');
});

it('resets a password and disables or enables an account', function () {
    $user = User::factory()->create();
    $old = $user->password;

    $this->actingAs($this->admin)->post(route('users.reset-password', $user))->assertSessionHas('generated_password');
    expect($user->fresh()->password)->not->toBe($old);

    $this->post(route('users.toggle-status', $user));
    expect($user->fresh()->is_disabled)->toBeTrue();
    $this->post(route('users.toggle-status', $this->admin))->assertSessionHas('error');
});
