<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\MatricNumberGenerator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    \App\Models\Setting::put('matric.issue_at', 'admission'); // these tests cover the issue-at-admission option; clearance has its own tests
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();

    $faculty = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $dept    = Department::create(['faculty_id' => $faculty->id, 'name' => 'Computer Science', 'code' => 'CSC']);
    $this->programme = Programme::create(['department_id' => $dept->id, 'name' => 'B.Sc. Computer Science', 'code' => 'BSC-CSC', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);
});

function applicantData(array $over = []): array
{
    return array_merge([
        'first_name' => 'Ada', 'last_name' => 'Okafor', 'email' => 'ada@example.com',
        'phone_number' => '08012345678', 'gender' => 'female', 'date_of_birth' => '2006-03-04',
        'state_of_origin' => 'Kogi', 'lga' => 'Kabba/Bunu', 'nationality' => 'Nigerian',
        'programme_id' => test()->programme->id, 'academic_session_id' => test()->session->id,
        'entry_mode' => 'utme', 'entry_level' => 100,
        'guardian_name' => 'Mr Okafor', 'guardian_relationship' => 'Father', 'guardian_phone' => '08099999999',
    ], $over);
}

function makeApplicant(array $over = []): Applicant
{
    test()->actingAs(test()->admin)->post(route('applicants.store'), applicantData($over))->assertSessionHasNoErrors();

    return Applicant::latest('id')->firstOrFail();
}

it('records an applicant with an application number', function () {
    $applicant = makeApplicant();

    expect($applicant->status)->toBe('pending')
        ->and($applicant->application_no)->toBe(sprintf('APP/2025/%04d', $applicant->id));
});

it('validates entry mode and level against the programme', function () {
    $this->actingAs($this->admin);

    $this->post(route('applicants.store'), applicantData(['entry_level' => 200]))->assertSessionHasErrors('entry_level'); // UTME must be 100
    $this->post(route('applicants.store'), applicantData(['entry_mode' => 'direct_entry', 'entry_level' => 100]))->assertSessionHasErrors('entry_level');
    $this->post(route('applicants.store'), applicantData(['entry_mode' => 'direct_entry', 'entry_level' => 600]))->assertSessionHasErrors('entry_level'); // 4-year programme
    $this->post(route('applicants.store'), applicantData(['entry_mode' => 'direct_entry', 'entry_level' => 200]))->assertSessionHasNoErrors();
    expect(Applicant::count())->toBe(1);
});

it('rejects duplicate emails, including an existing user account', function () {
    makeApplicant();
    $this->post(route('applicants.store'), applicantData())->assertSessionHasErrors('email');
    $this->post(route('applicants.store'), applicantData(['email' => $this->admin->email]))->assertSessionHasErrors('email');
});

it('does not allow applications to inactive programmes', function () {
    $this->programme->update(['is_active' => false]);
    $this->actingAs($this->admin)->post(route('applicants.store'), applicantData())->assertSessionHasErrors('programme_id');
});

it('admits an applicant: matric number, login, student role and flashed password', function () {
    $applicant = makeApplicant();

    $this->actingAs($this->admin)->post(route('applicants.admit', $applicant->id))
        ->assertSessionHas('generated_password', fn ($p) => $p['email'] === 'ada@example.com' && strlen($p['password']) >= 10)
        ->assertSessionHas('success');

    $student = Student::with('user')->firstOrFail();
    expect($student->matric_number)->toBe('2025/BSC-CSC/0001')
        ->and($student->current_level)->toBe(100)
        ->and($student->status)->toBe('active')
        ->and($student->guardian_name)->toBe('Mr Okafor')
        ->and($student->user->hasRole('Student'))->toBeTrue()
        ->and($student->user->username)->toBe('2025/BSC-CSC/0001')
        ->and($student->user->must_change_password)->toBeTrue()
        ->and($student->user->getAllPermissions())->toHaveCount(0)
        ->and($applicant->fresh()->status)->toBe('admitted');

    // cannot be admitted twice
    $this->post(route('applicants.admit', $applicant->id))->assertSessionHas('error');
    expect(Student::count())->toBe(1);
});

it('lets an admitted student sign in with the temporary password and see the student page', function () {
    $applicant = makeApplicant();
    $admission = app(AdmissionService::class)->admit($applicant);
    $student = $admission->student;
    $password = $admission->temporaryPassword;
    auth()->logout();

    $this->post('/login', ['email' => 'ada@example.com', 'password' => $password])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk()->assertSee('Welcome, Ada')->assertSee('2025/BSC-CSC/0001');
    $this->get(route('students.index'))->assertForbidden();
    $this->get(route('applicants.index'))->assertForbidden();
});

it('numbers students in sequence per programme and entry year', function () {
    $other = Programme::create(['department_id' => $this->programme->department_id, 'name' => 'B.Sc. Physics', 'code' => 'BSC-PHY', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
    $next  = AcademicSession::create(['name' => '2026/2027']);

    $service = app(AdmissionService::class);
    $matrics = collect([
        makeApplicant(['email' => 'a@example.com']),
        makeApplicant(['email' => 'b@example.com']),
        makeApplicant(['email' => 'c@example.com', 'programme_id' => $other->id]),
        makeApplicant(['email' => 'd@example.com', 'academic_session_id' => $next->id]),
    ])->map(fn ($a) => $service->admit($a)->student->matric_number)->all();

    expect($matrics)->toBe(['2025/BSC-CSC/0001', '2025/BSC-CSC/0002', '2025/BSC-PHY/0001', '2026/BSC-CSC/0001']);
});

it('uses the configured matric pattern and continues after existing numbers', function () {
    Setting::put(MatricNumberGenerator::SETTING_KEY, '{DEPARTMENT_CODE}/{SHORT_YEAR}/{SEQ:3}');
    $service = app(AdmissionService::class);

    // a student already holding CSC/25/007 (e.g. migrated) is respected
    $first = $service->admit(makeApplicant(['email' => 'first@example.com']))->student;
    expect($first->matric_number)->toBe('CSC/25/001');

    $first->update(['matric_number' => 'CSC/25/007']);
    \Illuminate\Support\Facades\DB::table('matric_sequences')->delete();

    $second = $service->admit(makeApplicant(['email' => 'second@example.com']))->student;
    expect($second->matric_number)->toBe('CSC/25/008');
});

it('validates matric patterns', function () {
    expect(MatricNumberGenerator::validate('{YEAR}/{PROGRAMME_CODE}/{SEQ:4}'))->toBeNull()
        ->and(MatricNumberGenerator::validate('{YEAR}/{PROGRAMME_CODE}'))->toContain('running number')
        ->and(MatricNumberGenerator::validate('{SEQ:4}/{SEQ:3}'))->toContain('exactly one')
        ->and(MatricNumberGenerator::validate('{FOO}/{SEQ:4}'))->toContain('Unknown part')
        ->and(MatricNumberGenerator::validate('{SEQ:9}'))->not->toBeNull()
        ->and(MatricNumberGenerator::validate('A B/{SEQ:4}'))->toContain('spaces')
        ->and(MatricNumberGenerator::validate('{YEAR/{SEQ:4}'))->not->toBeNull();
});

it('saves a matric format and previews it without saving', function () {
    $this->actingAs($this->admin);

    $this->get(route('students.settings'))->assertOk()->assertSee('2025/BSC-CSC/0001');
    $this->get(route('students.settings', ['pattern' => '{SHORT_YEAR}-{SEQ:3}']))->assertOk()->assertSee('25-001');
    expect(MatricNumberGenerator::pattern())->toBe(MatricNumberGenerator::DEFAULT_PATTERN);

    $this->put(route('students.settings.update'), ['pattern' => 'nonsense'])->assertSessionHasErrors('pattern');
    $this->put(route('students.settings.update'), ['pattern' => '{SHORT_YEAR}-{SEQ:3}'])->assertSessionHas('success');
    expect(MatricNumberGenerator::pattern())->toBe('{SHORT_YEAR}-{SEQ:3}');
});

it('rejects an applicant and still allows a later admission', function () {
    $applicant = makeApplicant();
    $this->actingAs($this->admin)->post(route('applicants.reject', $applicant->id))->assertSessionHas('success');
    expect($applicant->fresh()->status)->toBe('rejected');

    $this->post(route('applicants.reject', $applicant->id))->assertSessionHas('error'); // only pending
    $this->post(route('applicants.admit', $applicant->id))->assertSessionHas('success');
    expect(Student::count())->toBe(1);
});

it('locks admitted applicants against editing and deletion but deletes pending ones', function () {
    $this->actingAs($this->admin);
    $admitted = makeApplicant(['email' => 'x@example.com']);
    app(AdmissionService::class)->admit($admitted);
    $pending = makeApplicant(['email' => 'y@example.com']);

    $this->put(route('applicants.update', $admitted->id), applicantData(['email' => 'x@example.com', 'first_name' => 'Changed']))->assertSessionHas('error');
    $this->delete(route('applicants.destroy', $admitted->id))->assertSessionHas('error');
    expect($admitted->fresh()->first_name)->toBe('Ada');

    $this->put(route('applicants.update', $pending->id), applicantData(['email' => 'y@example.com', 'first_name' => 'Bola']))->assertSessionHasNoErrors();
    expect($pending->fresh()->first_name)->toBe('Bola');
    $this->delete(route('applicants.destroy', $pending->id));
    expect(Applicant::find($pending->id))->toBeNull();
});

it('lists, filters and shows students', function () {
    makeApplicant();
    $this->post(route('applicants.admit', Applicant::first()->id));
    $student = Student::firstOrFail();

    $this->get(route('students.index'))->assertOk()->assertSee('2025/BSC-CSC/0001')->assertSee('Okafor Ada');
    $this->get(route('students.index', ['q' => 'Okafor']))->assertSee('2025/BSC-CSC/0001');
    $this->get(route('students.index', ['status' => 'graduated']))->assertDontSee('2025/BSC-CSC/0001');
    $this->get(route('students.show', $student->id))->assertOk()->assertSee('Mr Okafor')->assertSee('Kabba/Bunu');
});

it('edits a student and keeps the login account in step', function () {
    makeApplicant();
    $this->actingAs($this->admin)->post(route('applicants.admit', Applicant::first()->id));
    $student = Student::firstOrFail();

    $payload = array_merge(
        Arr::only(applicantData(), ['first_name', 'last_name', 'phone_number', 'gender', 'date_of_birth', 'state_of_origin', 'lga', 'nationality', 'guardian_name']),
        ['email' => 'ada.new@example.com', 'current_level' => 200, 'first_name' => 'Adaeze']
    );

    $this->put(route('students.update', $student->id), $payload)->assertSessionHasNoErrors();

    expect($student->fresh()->current_level)->toBe(200)
        ->and($student->user->fresh()->email)->toBe('ada.new@example.com')
        ->and($student->user->fresh()->name)->toBe('Adaeze Okafor');

    $this->put(route('students.update', $student->id), array_merge($payload, ['current_level' => 500]))->assertSessionHasErrors('current_level');
    $this->put(route('students.update', $student->id), array_merge($payload, ['email' => $this->admin->email]))->assertSessionHasErrors('email');
});

it('does not allow creating or deleting students directly', function () {
    $this->actingAs($this->admin);
    $this->post('/students', [])->assertStatus(405);
    $this->delete('/students/1')->assertStatus(405);
});

it('uploads a passport photo and replaces the old one', function () {
    Storage::fake('public');
    makeApplicant();
    $this->actingAs($this->admin)->post(route('applicants.admit', Applicant::first()->id));
    $student = Student::firstOrFail();

    $this->post(route('students.photo', $student->id), ['photo' => UploadedFile::fake()->image('a.jpg', 200, 200)])->assertSessionHasNoErrors();
    $first = $student->fresh()->photo;
    Storage::disk('public')->assertExists($first);

    $this->post(route('students.photo', $student->id), ['photo' => UploadedFile::fake()->image('b.png', 200, 200)]);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($student->fresh()->photo);

    $this->post(route('students.photo', $student->id), ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
    $this->post(route('students.photo', $student->id), ['photo' => UploadedFile::fake()->image('big.jpg')->size(5000)])->assertSessionHasErrors('photo');
});

it('blocks suspended and withdrawn students from signing in', function () {
    $admission = app(AdmissionService::class)->admit(makeApplicant());
    $student = $admission->student;
    $password = $admission->temporaryPassword;

    $this->actingAs($this->admin)->post(route('students.status', $student->id), ['status' => 'suspended'])->assertSessionHas('success');
    expect($student->user->fresh()->is_disabled)->toBeTrue();
    auth()->logout();
    $this->post('/login', ['email' => 'ada@example.com', 'password' => $password])->assertSessionHasErrors('email');

    $this->actingAs($this->admin)->post(route('students.status', $student->id), ['status' => 'graduated']);
    expect($student->user->fresh()->is_disabled)->toBeFalse();
    $this->post(route('students.status', $student->id), ['status' => 'nonsense'])->assertSessionHasErrors('status');
});

it('resets a student password', function () {
    $admission = app(AdmissionService::class)->admit(makeApplicant());
    $student = $admission->student;
    $old = $admission->temporaryPassword;

    $this->actingAs($this->admin)->post(route('students.reset-password', $student->id))
        ->assertSessionHas('generated_password', fn ($p) => $p['password'] !== $old);
    expect(\Illuminate\Support\Facades\Hash::check($old, $student->user->fresh()->password))->toBeFalse();
});

it('enforces the separate admissions permissions', function () {
    $applicant = makeApplicant();
    $clerk = User::factory()->create();
    $clerk->givePermissionTo(['View applicants', 'Create applicants']);
    auth()->logout();

    $this->actingAs($clerk);
    $this->get(route('applicants.index'))->assertOk()->assertDontSee('title="Admit"', false);
    $this->post(route('applicants.store'), applicantData(['email' => 'new@example.com']))->assertSessionHasNoErrors();
    $this->post(route('applicants.admit', $applicant->id))->assertForbidden();
    $this->post(route('applicants.reject', $applicant->id))->assertForbidden();
    $this->put(route('applicants.update', $applicant->id), applicantData())->assertForbidden();
    $this->delete(route('applicants.destroy', $applicant->id))->assertForbidden();
    $this->get(route('students.index'))->assertForbidden();
    $this->get(route('students.settings'))->assertForbidden();

    $clerk->givePermissionTo('View students');
    $student = app(AdmissionService::class)->admit($applicant)->student;
    $this->get(route('students.show', $student->id))->assertOk();
    $this->post(route('students.status', $student->id), ['status' => 'deferred'])->assertForbidden();
    $this->post(route('students.reset-password', $student->id))->assertForbidden();
    $this->post(route('students.photo', $student->id), [])->assertForbidden();
    $this->put(route('students.update', $student->id), [])->assertForbidden();
});

it('refuses to delete a user who is a student', function () {
    $student = app(AdmissionService::class)->admit(makeApplicant())->student;

    $this->actingAs($this->admin)->delete(route('users.destroy', $student->user_id))->assertSessionHas('error');
    expect(User::find($student->user_id))->not->toBeNull();
});
