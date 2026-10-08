<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Programme;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\ApplicationService;
use App\Services\InvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();

    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $dept    = Department::create(['faculty_id' => $faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $this->programme = Programme::create(['department_id' => $dept->id, 'name' => 'B.Ed. Biology', 'code' => 'BED-BIO', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);

    openApplications();
});

function openApplications(array $over = []): void
{
    $values = array_merge([
        'open' => '1', 'session_id' => (string) test()->session->id, 'fee' => '2000', 'min_utme_score' => '150',
        'deadline' => null, 'payment_instructions' => 'Pay to AFUED account 0123456789.',
    ], $over);
    foreach ($values as $k => $v) {
        Setting::put("application.$k", $v);
    }
}

function applyData(array $over = []): array
{
    return array_merge([
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'email' => 'tola@example.com', 'phone_number' => '08031234567',
        'jamb_reg_no' => '12345678ab', 'entry_mode' => 'utme', 'jamb_score' => 210, 'programme_id' => test()->programme->id,
        'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
    ], $over);
}

function register(array $over = []): Applicant
{
    test()->post(route('apply.store'), applyData($over))->assertSessionHasNoErrors()->assertRedirect(route('apply.dashboard'));

    return Applicant::latest('id')->firstOrFail();
}

function completeApplication(Applicant $applicant): void
{
    test()->put(route('apply.profile'), [
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'phone_number' => '08031234567', 'gender' => 'female', 'date_of_birth' => '2007-05-06',
        'nationality' => 'Nigerian', 'state_of_origin' => 'Ondo', 'lga' => 'Ondo West', 'address' => '1 Palace Road, Ondo', 'jamb_score' => 210,
        'guardian_name' => 'Mrs Adeyemi', 'guardian_relationship' => 'Mother', 'guardian_phone' => '08030000000',
    ])->assertSessionHasNoErrors();

    foreach ([['English Language', 'B3'], ['Mathematics', 'C4'], ['Biology', 'B2'], ['Chemistry', 'C5'], ['Physics', 'C6']] as [$subject, $grade]) {
        test()->post(route('apply.results.store'), ['exam_type' => 'waec', 'exam_year' => 2025, 'exam_number' => '4120987654', 'subject' => $subject, 'grade' => $grade])->assertSessionHasNoErrors();
    }

    test()->post(route('apply.documents.store'), ['type' => 'passport', 'file' => UploadedFile::fake()->image('p.jpg')->size(30)])->assertSessionHasNoErrors();
    test()->post(route('apply.documents.store'), ['type' => 'olevel', 'file' => UploadedFile::fake()->create('olevel.pdf', 300, 'application/pdf')])->assertSessionHasNoErrors();
    test()->post(route('apply.documents.store'), ['type' => 'jamb_result', 'file' => UploadedFile::fake()->create('jamb.pdf', 300, 'application/pdf')])->assertSessionHasNoErrors();
}

function payScreeningFee(Applicant $applicant): void
{
    $invoice = $applicant->invoices()->firstOrFail();
    app(InvoiceService::class)->recordOfflinePayment($invoice, (float) $invoice->total, 'bank_deposit', 'TLR-' . $applicant->id, null, null, test()->admin->id);
}

it('shows the closed page when applications are closed, past deadline, or have no session', function () {
    Setting::put('application.open', '0');
    $this->get(route('apply.register'))->assertOk()->assertSee('Applications are closed');
    $this->post(route('apply.store'), applyData())->assertForbidden();

    openApplications(['deadline' => now()->subDay()->toDateString()]);
    $this->get(route('apply.register'))->assertSee('deadline')->assertSee('has passed');
    $this->post(route('apply.store'), applyData())->assertForbidden();

    openApplications(['deadline' => now()->toDateString()]);   // the closing day itself is still open
    $this->get(route('apply.register'))->assertDontSee('Applications are closed');

    openApplications(['session_id' => null]);
    $this->get(route('apply.register'))->assertSee('Applications are closed');
});

it('registers an applicant: draft status, application number, screening invoice, signed in', function () {
    $applicant = register();

    expect($applicant->status)->toBe('draft')
        ->and($applicant->application_no)->toBe(sprintf('APP/2026/%04d', $applicant->id))
        ->and($applicant->jamb_reg_no)->toBe('12345678AB')
        ->and($applicant->entry_level)->toBe(100)
        ->and($applicant->academic_session_id)->toBe($this->session->id)
        ->and($applicant->getAttributes()['password'])->not->toBe('secret-pass-1');

    $invoice = $applicant->invoices()->first();
    expect($invoice->total)->toBe('2000.00')->and($invoice->payable_type)->toBe(Applicant::class);

    $this->get(route('apply.dashboard'))->assertOk()->assertSee($applicant->application_no)->assertSee('Pay to AFUED account 0123456789.')->assertSee($invoice->reference);
});

it('enters Direct Entry applicants at 200 level without needing a UTME score', function () {
    $applicant = register(['entry_mode' => 'direct_entry', 'jamb_score' => null]);
    expect($applicant->entry_level)->toBe(200)->and($applicant->jamb_score)->toBeNull();
});

it('validates the registration form', function () {
    $this->post(route('apply.store'), applyData(['jamb_reg_no' => '1234']))->assertSessionHasErrors('jamb_reg_no');
    $this->post(route('apply.store'), applyData(['jamb_score' => 120]))->assertSessionHasErrors('jamb_score');   // below 150
    $this->post(route('apply.store'), applyData(['jamb_score' => null]))->assertSessionHasErrors('jamb_score');
    $this->post(route('apply.store'), applyData(['entry_mode' => 'transfer']))->assertSessionHasErrors('entry_mode');
    $this->post(route('apply.store'), applyData(['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
    $this->post(route('apply.store'), applyData(['password_confirmation' => 'different']))->assertSessionHasErrors('password');
    $this->post(route('apply.store'), applyData(['email' => $this->admin->email]))->assertSessionHasErrors('email');

    $this->programme->update(['is_active' => false]);
    $this->post(route('apply.store'), applyData())->assertSessionHasErrors('programme_id');
    expect(Applicant::count())->toBe(0);

    $this->programme->update(['is_active' => true]);
    register();
    $this->post(route('apply.store'), applyData(['email' => 'other@example.com']))->assertSessionHasErrors('jamb_reg_no');   // same JAMB number
    $this->post(route('apply.store'), applyData(['jamb_reg_no' => '99999999ZZ']))->assertSessionHasErrors('email');
});

it('signs applicants back in by email, JAMB number or application number, and throttles guessing', function () {
    $applicant = register();
    $this->post(route('apply.logout'));
    $this->get(route('apply.dashboard'))->assertRedirect(route('apply.login'));

    foreach (['tola@example.com', '12345678ab', $applicant->application_no] as $login) {
        $this->post(route('apply.login.attempt'), ['login' => $login, 'password' => 'secret-pass-1'])->assertRedirect(route('apply.dashboard'));
        $this->post(route('apply.logout'));
    }

    foreach (range(1, 5) as $i) {
        $this->post(route('apply.login.attempt'), ['login' => 'tola@example.com', 'password' => 'wrong'])->assertSessionHasErrors('login');
    }
    $this->post(route('apply.login.attempt'), ['login' => 'tola@example.com', 'password' => 'secret-pass-1'])
        ->assertSessionHasErrors('login', 'Too many attempts');    // even the right password waits
    $this->get(route('apply.dashboard'))->assertRedirect(route('apply.login'));
});

it('blocks submission until the checklist is complete, then submits and locks the application', function () {
    $applicant = register();
    $this->post(route('apply.submit'))->assertSessionHas('error', fn ($m) => str_contains($m, 'Pay the screening fee') && str_contains($m, 'Complete your personal details'));
    expect($applicant->fresh()->status)->toBe('draft');

    completeApplication($applicant);
    $this->post(route('apply.submit'))->assertSessionHas('error', fn ($m) => str_contains($m, 'Pay the screening fee') && ! str_contains($m, 'personal details'));

    payScreeningFee($applicant);
    $this->get(route('apply.dashboard'))->assertSee('Submit application');
    $this->post(route('apply.submit'))->assertSessionHas('success');

    $applicant->refresh();
    expect($applicant->status)->toBe('pending')->and($applicant->submitted_at)->not->toBeNull();

    // locked
    $this->put(route('apply.profile'), ['first_name' => 'Changed'])->assertForbidden();
    $this->post(route('apply.results.store'), ['exam_type' => 'waec', 'exam_year' => 2025, 'exam_number' => '1', 'subject' => 'Art', 'grade' => 'A1'])->assertForbidden();
    $this->post(route('apply.documents.store'), ['type' => 'passport', 'file' => UploadedFile::fake()->image('p.jpg')->size(10)])->assertForbidden();
    $this->post(route('apply.submit'))->assertSessionHas('error');
});

it('needs five credits including English and Mathematics, and an NCE/ND for Direct Entry', function () {
    $applicant = register();
    $service = app(ApplicationService::class);
    $olevelDone = fn () => collect($service->checklist($applicant->fresh()))->firstWhere(0, 'olevel')[2];

    foreach ([['English Language', 'B3'], ['Mathematics', 'C4'], ['Biology', 'B2'], ['Chemistry', 'C5']] as [$s, $g]) {
        $this->post(route('apply.results.store'), ['exam_type' => 'waec', 'exam_year' => 2025, 'exam_number' => '1', 'subject' => $s, 'grade' => $g]);
    }
    expect($olevelDone())->toBeFalse();                                   // only 4 credits

    $this->post(route('apply.results.store'), ['exam_type' => 'waec', 'exam_year' => 2025, 'exam_number' => '1', 'subject' => 'Physics', 'grade' => 'D7']);
    expect($olevelDone())->toBeFalse();                                   // D7 is a pass, not a credit

    $this->post(route('apply.results.store'), ['exam_type' => 'waec', 'exam_year' => 2025, 'exam_number' => '1', 'subject' => 'Economics', 'grade' => 'C6']);
    expect($olevelDone())->toBeTrue();

    // no Maths credit -> fails even with five credits
    $other = register(['email' => 'o@example.com', 'jamb_reg_no' => '11111111AA']);
    foreach (['English Language', 'Biology', 'Chemistry', 'Physics', 'Economics'] as $s) {
        $this->post(route('apply.results.store'), ['exam_type' => 'waec', 'exam_year' => 2025, 'exam_number' => '1', 'subject' => $s, 'grade' => 'A1']);
    }
    expect(collect($service->checklist($other->fresh()))->firstWhere(0, 'olevel')[2])->toBeFalse();

    // Direct Entry also needs an NCE/ND result and that document type
    $de = register(['email' => 'de@example.com', 'jamb_reg_no' => '22222222BB', 'entry_mode' => 'direct_entry', 'jamb_score' => null]);
    $keys = collect($service->checklist($de->fresh()))->pluck(0)->all();
    expect($keys)->toContain('nce_nd')->toContain('doc:nce_nd')->not->toContain('doc:jamb_result');
    $this->post(route('apply.results.store'), ['exam_type' => 'nce', 'exam_year' => 2025, 'exam_number' => 'N1', 'subject' => 'Biology', 'grade' => 'Credit']);
    expect(collect($service->checklist($de->fresh()))->firstWhere(0, 'nce_nd')[2])->toBeTrue();
});

it('validates results: grade must fit the exam, no duplicates, only your own can be removed', function () {
    $applicant = register();
    $good = ['exam_type' => 'waec', 'exam_year' => 2025, 'exam_number' => '1', 'subject' => 'english language', 'grade' => 'B3'];

    $this->post(route('apply.results.store'), array_merge($good, ['grade' => 'Distinction']))->assertSessionHasErrors('grade');
    $this->post(route('apply.results.store'), array_merge($good, ['exam_type' => 'nd', 'grade' => 'B3']))->assertSessionHasErrors('grade');
    $this->post(route('apply.results.store'), array_merge($good, ['exam_year' => 1980]))->assertSessionHasErrors('exam_year');
    $this->post(route('apply.results.store'), $good)->assertSessionHasNoErrors();
    $this->post(route('apply.results.store'), $good)->assertSessionHas('error');                 // duplicate
    expect($applicant->results()->first()->subject)->toBe('English Language');

    $mine = $applicant->results()->first();
    $stranger = Applicant::create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com', 'programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'status' => 'draft']);
    $theirs = $stranger->results()->create($good);
    $this->delete(route('apply.results.destroy', $theirs->id))->assertNotFound();
    $this->delete(route('apply.results.destroy', $mine->id))->assertSessionHas('success');
});

it('enforces the passport rules and keeps documents private to their owner and staff', function () {
    $applicant = register();
    $upload = fn ($type, $file) => $this->post(route('apply.documents.store'), ['type' => $type, 'file' => $file]);

    $upload('passport', UploadedFile::fake()->image('big.jpg')->size(80))->assertSessionHasErrors('file');       // over 50 KB
    $upload('passport', UploadedFile::fake()->image('p.png')->size(20))->assertSessionHasErrors('file');         // not a JPEG
    $upload('olevel', UploadedFile::fake()->create('huge.pdf', 3000, 'application/pdf'))->assertSessionHasErrors('file');
    $upload('olevel', UploadedFile::fake()->create('virus.exe', 10))->assertSessionHasErrors('file');
    $upload('selfie', UploadedFile::fake()->image('s.jpg'))->assertSessionHasErrors('type');

    $upload('passport', UploadedFile::fake()->image('p.jpg')->size(20))->assertSessionHasNoErrors();
    $first = $applicant->documents()->where('type', 'passport')->first();
    Storage::disk('local')->assertExists($first->path);
    Storage::disk('public')->assertDirectoryEmpty('/');                                                           // never on the public disk

    $upload('passport', UploadedFile::fake()->image('p2.jpg')->size(20));                                          // replaces
    Storage::disk('local')->assertMissing($first->path);
    expect($applicant->documents()->where('type', 'passport')->count())->toBe(1);
    $doc = $applicant->documents()->where('type', 'passport')->first();

    $this->get(route('apply.documents.show', $doc->id))->assertOk();                                               // the owner
    $this->post(route('apply.logout'));
    $this->get(route('apply.documents.show', $doc->id))->assertRedirect(route('apply.login'));                     // not signed in
    $this->get(route('applicants.documents.show', $doc->id))->assertRedirect(route('login'));

    $other = Applicant::create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com', 'programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'status' => 'draft', 'password' => 'pw-pw-pw-pw']);
    $this->withSession(['applicant_id' => $other->id])->get(route('apply.documents.show', $doc->id))->assertNotFound(); // another applicant

    $this->flushSession();
    $this->actingAs($this->admin)->get(route('applicants.documents.show', $doc->id))->assertOk();                  // staff
    $nobody = User::factory()->create();
    $this->actingAs($nobody)->get(route('applicants.documents.show', $doc->id))->assertForbidden();
});

it('shows the screening slip only after submitting', function () {
    $applicant = register();
    $this->get(route('apply.slip'))->assertForbidden();

    completeApplication($applicant);
    payScreeningFee($applicant);
    $this->post(route('apply.submit'));

    $this->get(route('apply.slip'))->assertOk()->assertSee('screening slip')->assertSee($applicant->application_no)->assertSee('12345678AB')->assertSee('B.Ed. Biology');
});

it('lets the bursary confirm the screening fee from the invoices page', function () {
    $applicant = register();
    $invoice = $applicant->invoices()->first();
    auth()->logout();
    $this->flushSession();

    $this->actingAs($this->admin);
    $this->get(route('invoices.index', ['q' => $applicant->application_no]))->assertOk()->assertSee($invoice->reference)->assertSee($applicant->application_no);
    $this->get(route('invoices.index', ['q' => 'Adeyemi']))->assertSee($invoice->reference);
    $this->get(route('invoices.show', $invoice->id))->assertOk()->assertSee('Adeyemi Tola')->assertSee($applicant->application_no);
    $this->post(route('invoices.payments.store', $invoice->id), ['amount' => 2000, 'channel' => 'bank_deposit', 'gateway_reference' => 'TLR-77'])->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe('paid');
    $this->get(route('payments.receipt', \App\Models\Payment::first()->id))->assertOk()->assertSee('Application number');
});

it('waives the checklist fee item when the fee is zero', function () {
    openApplications(['fee' => '0']);
    $applicant = register();

    expect($applicant->invoices()->count())->toBe(0)
        ->and(collect(app(ApplicationService::class)->checklist($applicant))->firstWhere(0, 'fee')[2])->toBeTrue();
});

it('gives admins the application list, details, settings and batch admission with separate permissions', function () {
    $applicant = register();
    completeApplication($applicant);
    payScreeningFee($applicant);
    $this->post(route('apply.submit'));
    $this->post(route('apply.logout'));

    $this->actingAs($this->admin);
    $this->get(route('applicants.index'))->assertOk()->assertSee('12345678AB', false)->assertSee('Admit batch');
    $this->get(route('applicants.index', ['q' => '12345678AB']))->assertSee('Adeyemi Tola');
    $this->get(route('applicants.show', $applicant->id))->assertOk()->assertSee('English Language')->assertSee('Mrs Adeyemi')->assertSee('Passport photograph');
    $this->get(route('applicants.settings'))->assertOk()->assertSee('Accept online applications');

    $clerk = User::factory()->create();
    $clerk->givePermissionTo(['View applicants', 'View application settings']);
    $this->actingAs($clerk);
    $this->get(route('applicants.show', $applicant->id))->assertOk()->assertDontSee('Admit applicant');
    $this->put(route('applicants.settings.update'), ['fee' => 1, 'min_utme_score' => 150])->assertForbidden();
    $this->post(route('applicants.admit-batch'), ['batch' => 'B1'])->assertForbidden();
    $this->post(route('applicants.admit', $applicant->id))->assertForbidden();
});

it('saves application settings and validates them', function () {
    $this->actingAs($this->admin);

    $this->put(route('applicants.settings.update'), ['open' => 1, 'fee' => 1500, 'min_utme_score' => 160])->assertSessionHasErrors('academic_session_id');
    $this->put(route('applicants.settings.update'), ['open' => 0, 'fee' => -5, 'min_utme_score' => 500])->assertSessionHasErrors(['fee', 'min_utme_score']);

    $this->put(route('applicants.settings.update'), [
        'open' => 1, 'academic_session_id' => $this->session->id, 'deadline' => now()->addMonth()->toDateString(),
        'fee' => 1500, 'min_utme_score' => 160, 'instructions' => 'Read carefully.', 'payment_instructions' => 'Pay at Zenith.',
    ])->assertSessionHasNoErrors();

    expect(\App\Services\ApplicationSettings::isOpen())->toBeTrue()
        ->and(\App\Services\ApplicationSettings::fee())->toBe(1500.0)
        ->and(\App\Services\ApplicationSettings::minUtmeScore())->toBe(160);

    $this->put(route('applicants.settings.update'), ['open' => 0, 'fee' => 1500, 'min_utme_score' => 160])->assertSessionHasNoErrors();
    expect(\App\Services\ApplicationSettings::isOpen())->toBeFalse();
});

it('admits an online applicant: keeps their password, copies the passport photo, records the batch', function () {
    $applicant = register();
    completeApplication($applicant);
    payScreeningFee($applicant);
    $this->post(route('apply.submit'));
    $this->post(route('apply.logout'));

    $this->actingAs($this->admin)->post(route('applicants.admit', $applicant->id))
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'password they chose'))
        ->assertSessionMissing('generated_password');

    $student = Student::with('user')->firstOrFail();
    expect($student->user->must_change_password)->toBeFalse()
        ->and(Hash::check('secret-pass-1', $student->user->password))->toBeTrue()
        ->and($student->photo)->not->toBeNull()
        ->and($student->current_level)->toBe(100);
    Storage::disk('public')->assertExists($student->photo);

    auth()->logout();
    $this->post('/login', ['email' => 'tola@example.com', 'password' => 'secret-pass-1'])->assertRedirect('/dashboard');
});

it('admits a whole batch and reports anyone it could not admit', function () {
    $ready = [];
    foreach ([['a@example.com', '10000001AA'], ['b@example.com', '10000002AA']] as [$email, $jamb]) {
        $a = register(['email' => $email, 'jamb_reg_no' => $jamb]);
        $a->update(['status' => 'pending', 'submitted_at' => now()]);
        $ready[] = $a;
        $this->post(route('apply.logout'));
    }
    $draft = register(['email' => 'draft@example.com', 'jamb_reg_no' => '10000003AA']);
    $this->post(route('apply.logout'));
    $blocked = Applicant::create(['first_name' => 'Blocked', 'last_name' => 'One', 'email' => 'blocked@example.com', 'programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'status' => 'pending']);
    User::factory()->create(['email' => 'blocked@example.com']);      // email already taken by an account

    $this->actingAs($this->admin)->post(route('applicants.admit-batch'), ['batch' => 'First Batch 2026/2027'])
        ->assertSessionHas('success', fn ($m) => str_contains($m, '2 applicant(s) admitted') && str_contains($m, 'already exists'));

    expect(Student::count())->toBe(2)
        ->and(Student::pluck('admission_batch')->unique()->all())->toBe(['First Batch 2026/2027'])
        ->and($draft->fresh()->status)->toBe('draft')
        ->and($blocked->fresh()->status)->toBe('pending');

    $this->post(route('applicants.admit-batch'), ['batch' => 'Second'])->assertSessionHas('error', fn ($m) => str_contains($m, '0 applicant(s)'));
    $this->post(route('applicants.admit-batch'), [])->assertSessionHasErrors('batch');
});

it('does not let draft applicants be admitted one by one either', function () {
    $draft = register();
    $this->actingAs($this->admin)->post(route('applicants.admit', $draft->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'not submitted'));
    expect(Student::count())->toBe(0);
});

it('still admits admin-entered applicants with a generated password', function () {
    $applicant = Applicant::create(['first_name' => 'Walk', 'last_name' => 'In', 'email' => 'walkin@example.com', 'programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'status' => 'pending']);
    [$student, $password] = app(AdmissionService::class)->admit($applicant);

    expect($password)->toBeString()->and($student->user->must_change_password)->toBeTrue();
});
