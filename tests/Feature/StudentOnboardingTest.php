<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\InvoiceService;
use App\Services\OnboardingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    \App\Models\Setting::put('matric.issue_at', 'admission'); // these tests cover the issue-at-admission option; clearance has its own tests
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();

    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $dept    = Department::create(['faculty_id' => $faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $this->programme = Programme::create(['department_id' => $dept->id, 'name' => 'B.Ed. Biology', 'code' => 'BED-BIO', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);

    $this->tuition = BillItem::create(['name' => 'Tuition', 'code' => 'TUI']);
    $this->accept  = BillItem::create(['name' => 'Acceptance fee', 'code' => 'ACC']);
    foreach ([[$this->tuition, 50000], [$this->accept, 20000]] as [$item, $amount]) {
        Bill::create(['academic_session_id' => $this->session->id, 'programme_id' => $this->programme->id, 'level' => 100, 'bill_item_id' => $item->id, 'student_type' => 'fresh', 'amount' => $amount]);
    }
});

function admitted(string $email = 'tola@example.com', string $jamb = '12345678AB', string $mode = 'utme'): Student
{
    $applicant = Applicant::create([
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'email' => $email, 'jamb_reg_no' => $jamb, 'jamb_score' => 200,
        'programme_id' => test()->programme->id, 'academic_session_id' => test()->session->id, 'entry_mode' => $mode,
        'entry_level' => $mode === 'utme' ? 100 : 200, 'status' => 'pending', 'password' => 'my-secret-pw',
    ]);
    foreach (['passport' => 'p.jpg', 'olevel' => 'o.pdf', 'jamb_result' => 'j.pdf'] as $type => $name) {
        $path = Storage::disk('local')->putFile("applicants/{$applicant->id}", UploadedFile::fake()->create($name, 10));
        $applicant->documents()->create(['type' => $type, 'path' => $path, 'original_name' => $name, 'size' => 10240]);
    }

    return app(AdmissionService::class)->admit($applicant, 'First Batch')[0];
}

function useAcceptanceFee(): void
{
    Setting::put('onboarding.acceptance_item_id', (string) test()->accept->id);
}

function signInAs(Student $student): void
{
    auth()->logout();
    test()->actingAs($student->user->fresh());
}

function fillBiodata(): array
{
    return [
        'phone_number' => '08031234567', 'gender' => 'female', 'date_of_birth' => '2007-05-06', 'nationality' => 'Nigerian',
        'state_of_origin' => 'Ondo', 'lga' => 'Ondo West', 'address' => '1 Palace Road, Ondo',
        'guardian_name' => 'Mrs Adeyemi', 'guardian_relationship' => 'Mother', 'guardian_phone' => '08030000000',
    ];
}

function finishOnlineSteps(Student $student): void
{
    signInAs($student);
    test()->post(route('my.onboarding.accept'));
    test()->put(route('my.onboarding.biodata'), fillBiodata())->assertSessionHasNoErrors();
    foreach (['birth_certificate', 'state_of_origin'] as $type) {
        test()->post(route('my.onboarding.documents.store'), ['type' => $type, 'file' => UploadedFile::fake()->create("$type.pdf", 50, 'application/pdf')])->assertSessionHasNoErrors();
    }
    if ($invoice = app(OnboardingService::class)->acceptanceInvoice($student->fresh())) {
        app(InvoiceService::class)->recordOfflinePayment($invoice, (float) $invoice->total, 'cash', null, null, null, test()->admin->id);
    }
}

it('lets an applicant check their status with JAMB number and surname only', function () {
    $student = admitted();
    $applicant = $student->applicant->fresh();

    $this->get(route('admission-status'))->assertOk()->assertSee('Check your admission status');
    $this->post(route('admission-status.check'), ['jamb_reg_no' => '12345678ab', 'last_name' => 'ADEYEMI'])
        ->assertOk()->assertSee('Congratulations, you have been offered admission')->assertSee('First Batch')->assertSee('tola@example.com');

    // a JAMB number alone, or a wrong surname, reveals nothing
    $this->post(route('admission-status.check'), ['jamb_reg_no' => '12345678AB', 'last_name' => 'Wrong'])
        ->assertOk()->assertSee('could not find')->assertDontSee('Tola')->assertDontSee('tola@example.com');
    $this->post(route('admission-status.check'), ['jamb_reg_no' => '00000000ZZ', 'last_name' => 'Adeyemi'])->assertSee('could not find');
    $this->post(route('admission-status.check'), ['jamb_reg_no' => '12345678AB'])->assertSessionHasErrors('last_name');

    foreach (['pending' => 'under review', 'rejected' => 'not successful', 'draft' => 'not submitted'] as $status => $text) {
        $applicant->update(['status' => $status]);
        $this->post(route('admission-status.check'), ['jamb_reg_no' => '12345678AB', 'last_name' => 'Adeyemi'])->assertSee($text);
    }
});

it('flags newly admitted students for onboarding but leaves existing students alone', function () {
    $student = admitted();
    expect($student->needs_onboarding)->toBeTrue()->and($student->offer_accepted_at)->toBeNull();

    $legacy = admitted('old@example.com', '99999999ZZ');
    $legacy->update(['needs_onboarding' => false]);
    signInAs($legacy);
    $this->get(route('my.onboarding'))->assertNotFound();
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Getting started');
});

it('shows the getting-started steps on the student home page', function () {
    $student = admitted();
    signInAs($student);

    $this->get(route('dashboard'))->assertOk()->assertSee('Getting started')->assertSee('step(s) left');
    $this->get(route('my.onboarding'))->assertOk()->assertSee('Accept my admission offer')->assertSee('Complete your biodata');
});

it('raises the acceptance invoice when the offer is accepted and keeps that fee out of session fees', function () {
    useAcceptanceFee();
    $student = admitted();
    signInAs($student);

    expect(collect(app(OnboardingService::class)->checklist($student))->pluck(0)->all())->toContain('fee');   // announced before accepting
    expect($student->invoices()->count())->toBe(0);

    $this->post(route('my.onboarding.accept'))->assertSessionHas('success');
    $this->post(route('my.onboarding.accept'));                                            // twice is harmless

    $student->refresh();
    $invoice = $student->invoices()->where('kind', 'acceptance')->get();
    expect($student->offer_accepted_at)->not->toBeNull()
        ->and($invoice)->toHaveCount(1)->and($invoice->first()->total)->toBe('20000.00')
        ->and($invoice->first()->title)->toBe('2026/2027 acceptance fee');

    $fees = app(InvoiceService::class)->generateForStudent($student, $this->session);
    expect($fees->total)->toBe('50000.00')->and($fees->lines->pluck('description')->all())->toBe(['Tuition'])
        ->and($fees->kind)->toBe('fees')
        ->and(app(InvoiceService::class)->sessionInvoice($student, $this->session)->id)->toBe($fees->id);   // not the acceptance invoice

    $this->get(route('my.onboarding'))->assertOk()->assertSee('₦20,000.00')->assertSee($invoice->first()->reference);
});

it('has no fee step when no acceptance fee is configured or no bill exists for the student', function () {
    $student = admitted();
    signInAs($student);
    $this->post(route('my.onboarding.accept'));
    expect(collect(app(OnboardingService::class)->checklist($student->fresh()))->pluck(0)->all())->not->toContain('fee')
        ->and($student->invoices()->count())->toBe(0);

    // configured, but nothing billed for this programme: must not leave an unfinishable step
    Bill::where('bill_item_id', $this->accept->id)->delete();
    useAcceptanceFee();
    $other = admitted('b@example.com', '11111111BB');
    signInAs($other);
    $this->post(route('my.onboarding.accept'));
    expect(collect(app(OnboardingService::class)->checklist($other->fresh()))->pluck(0)->all())->not->toContain('fee');
});

it('refuses to accept an offer for an inactive student', function () {
    $student = admitted();
    $student->update(['status' => 'suspended']);
    signInAs($student);

    $this->post(route('my.onboarding.accept'))->assertSessionHas('error');
    expect($student->fresh()->offer_accepted_at)->toBeNull();
});

it('validates and saves biodata, keeping the login account in step', function () {
    $student = admitted();
    signInAs($student);

    $this->put(route('my.onboarding.biodata'), array_merge(fillBiodata(), ['phone_number' => '']))->assertSessionHasErrors('phone_number');
    $this->put(route('my.onboarding.biodata'), array_merge(fillBiodata(), ['gender' => 'robot']))->assertSessionHasErrors('gender');
    $this->put(route('my.onboarding.biodata'), array_merge(fillBiodata(), ['date_of_birth' => now()->addDay()->toDateString()]))->assertSessionHasErrors('date_of_birth');

    $this->put(route('my.onboarding.biodata'), fillBiodata())->assertSessionHasNoErrors()->assertSessionHas('success');
    $student->refresh();
    expect($student->lga)->toBe('Ondo West')->and($student->user->phone_number)->toBe('08031234567')
        ->and(app(OnboardingService::class)->biodataComplete($student))->toBeTrue();

    // name and matric number are not editable here
    $this->put(route('my.onboarding.biodata'), array_merge(fillBiodata(), ['first_name' => 'Hacker', 'matric_number' => 'X']));
    expect($student->fresh()->first_name)->toBe('Tola')->and($student->fresh()->matric_number)->not->toBe('X');
});

it('asks for the extra documents and validates uploads', function () {
    $student = admitted();
    signInAs($student);
    $upload = fn ($type, $file) => $this->post(route('my.onboarding.documents.store'), ['type' => $type, 'file' => $file]);

    expect(array_keys(app(OnboardingService::class)->requiredDocuments($student)))->toBe(['passport', 'olevel', 'jamb_result', 'birth_certificate', 'state_of_origin']);

    $upload('birth_certificate', UploadedFile::fake()->create('big.pdf', 3000, 'application/pdf'))->assertSessionHasErrors('file');
    $upload('birth_certificate', UploadedFile::fake()->create('x.exe', 10))->assertSessionHasErrors('file');
    $upload('nce_nd', UploadedFile::fake()->create('n.pdf', 10, 'application/pdf'))->assertSessionHasErrors('type');   // UTME student: not required
    $upload('passport', UploadedFile::fake()->image('big.jpg')->size(80))->assertSessionHasErrors('file');

    $upload('birth_certificate', UploadedFile::fake()->create('bc.pdf', 100, 'application/pdf'))->assertSessionHasNoErrors();
    $doc = $student->applicant->documents()->where('type', 'birth_certificate')->first();
    Storage::disk('local')->assertExists($doc->path);

    $upload('birth_certificate', UploadedFile::fake()->create('bc2.pdf', 100, 'application/pdf'));                     // replaces
    Storage::disk('local')->assertMissing($doc->path);
    expect($student->applicant->documents()->where('type', 'birth_certificate')->count())->toBe(1);
});

it('keeps onboarding documents private to their owner and to staff', function () {
    $student = admitted();
    $other = admitted('b@example.com', '11111111BB');
    $doc = $student->applicant->documents()->first();

    signInAs($student);
    $this->get(route('my.onboarding.documents.show', $doc->id))->assertOk();
    signInAs($other);
    $this->get(route('my.onboarding.documents.show', $doc->id))->assertNotFound();

    auth()->logout();
    $this->get(route('my.onboarding.documents.show', $doc->id))->assertRedirect(route('login'));
    $this->get(route('verification.documents.show', $doc->id))->assertRedirect(route('login'));
    $this->actingAs($other->user)->get(route('verification.documents.show', $doc->id))->assertForbidden();
    $this->actingAs($this->admin)->get(route('verification.documents.show', $doc->id))->assertOk();
});

it('only lets a student be verified once every online step is done', function () {
    useAcceptanceFee();
    $student = admitted();
    $this->actingAs($this->admin);

    $this->post(route('verification.verify', $student->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'Accept your admission offer'));

    signInAs($student);
    $this->post(route('my.onboarding.accept'));
    $this->actingAs($this->admin)->post(route('verification.verify', $student->id))->assertSessionHas('error', fn ($m) => str_contains($m, 'Pay the acceptance fee') && str_contains($m, 'Complete your biodata'));
    expect($student->fresh()->verified_at)->toBeNull();

    finishOnlineSteps($student);
    expect(app(OnboardingService::class)->state($student->fresh()))->toBe('ready');

    $this->actingAs($this->admin)->post(route('verification.verify', $student->id), ['note' => 'Originals seen'])->assertSessionHas('success');
    $student->refresh();
    expect($student->verified_at)->not->toBeNull()->and($student->verified_by)->toBe($this->admin->id)->and($student->verification_note)->toBe('Originals seen')
        ->and(app(OnboardingService::class)->state($student))->toBe('verified');
});

it('locks the student out of editing once verified, until verification is undone', function () {
    $student = admitted();
    finishOnlineSteps($student);
    $this->actingAs($this->admin)->post(route('verification.verify', $student->id))->assertSessionHas('success');

    signInAs($student);
    $this->put(route('my.onboarding.biodata'), fillBiodata())->assertForbidden();
    $this->post(route('my.onboarding.documents.store'), ['type' => 'birth_certificate', 'file' => UploadedFile::fake()->create('n.pdf', 10, 'application/pdf')])->assertSessionHas('error');
    $this->get(route('my.onboarding'))->assertOk()->assertSee('You have been verified');

    $this->actingAs($this->admin)->post(route('verification.unverify', $student->id))->assertSessionHas('success');
    expect($student->fresh()->verified_at)->toBeNull();
    signInAs($student);
    $this->put(route('my.onboarding.biodata'), fillBiodata())->assertSessionHasNoErrors();
});

it('lists students by verification state for staff, with separate permissions', function () {
    $ready = admitted('a@example.com', '10000001AA');
    finishOnlineSteps($ready);
    $waiting = admitted('b@example.com', '10000002AA');
    $done = admitted('c@example.com', '10000003AA');
    finishOnlineSteps($done);
    $this->actingAs($this->admin)->post(route('verification.verify', $done->id));

    $this->actingAs($this->admin);
    $this->get(route('verification.index'))->assertOk()->assertSee($ready->matric_number)->assertDontSee($waiting->matric_number)->assertDontSee($done->matric_number)->assertSee('Verify');
    $this->get(route('verification.index', ['tab' => 'awaiting']))->assertSee($waiting->matric_number)->assertSee('Still to do');
    $this->get(route('verification.index', ['tab' => 'verified']))->assertSee($done->matric_number)->assertSee('Undo');
    $this->get(route('verification.index', ['q' => 'nobody']))->assertDontSee($ready->matric_number);
    $this->get(route('verification.index', ['batch' => 'No such batch']))->assertDontSee($ready->matric_number);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('View students');
    $this->actingAs($viewer);
    $this->get(route('verification.index'))->assertOk()->assertDontSee('placeholder="Note (optional)"', false);
    $this->post(route('verification.verify', $ready->id))->assertForbidden();
    $this->post(route('verification.unverify', $done->id))->assertForbidden();

    $this->actingAs(User::factory()->create())->get(route('verification.index'))->assertForbidden();
    $this->actingAs($ready->user)->get(route('verification.index'))->assertForbidden();
});

it('saves the acceptance fee bill item in the application settings', function () {
    $this->actingAs($this->admin);
    $payload = ['open' => 0, 'fee' => 2000, 'min_utme_score' => 150];

    $this->put(route('applicants.settings.update'), $payload + ['acceptance_item_id' => 9999])->assertSessionHasErrors('acceptance_item_id');
    $this->put(route('applicants.settings.update'), $payload + ['acceptance_item_id' => $this->accept->id])->assertSessionHasNoErrors();
    expect(\App\Services\ApplicationSettings::acceptanceItemId())->toBe($this->accept->id);

    $this->get(route('applicants.settings'))->assertOk()->assertSee('Bill item charged as the acceptance fee');
    $this->put(route('applicants.settings.update'), $payload)->assertSessionHasNoErrors();
    expect(\App\Services\ApplicationSettings::acceptanceItemId())->toBeNull();
});
