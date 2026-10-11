<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\ClearanceItem;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\ClearanceService;
use App\Services\InvoiceService;
use App\Services\OnboardingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
});

function applicantStudent(string $email = 'tola@example.com', string $jamb = '12345678AB'): Student
{
    $applicant = Applicant::create([
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'email' => $email, 'jamb_reg_no' => $jamb, 'jamb_score' => 200,
        'programme_id' => test()->programme->id, 'academic_session_id' => test()->session->id, 'entry_mode' => 'utme',
        'entry_level' => 100, 'status' => 'pending', 'password' => 'my-secret-pw',
    ]);
    $applicant->update(['application_no' => 'APP/2026/' . str_pad((string) $applicant->id, 4, '0', STR_PAD_LEFT)]);
    foreach (['passport' => 'p.jpg', 'olevel' => 'o.pdf', 'jamb_result' => 'j.pdf'] as $type => $name) {
        $path = Storage::disk('local')->putFile("applicants/{$applicant->id}", UploadedFile::fake()->create($name, 10));
        $applicant->documents()->create(['type' => $type, 'path' => $path, 'original_name' => $name, 'size' => 10240]);
    }

    return app(AdmissionService::class)->admit($applicant->fresh(), 'First Batch')->student;
}

/** Accept, biodata, documents, then physical verification. */
function getVerified(Student $student): void
{
    test()->actingAs($student->user->fresh());
    test()->post(route('my.onboarding.accept'));
    test()->put(route('my.onboarding.biodata'), [
        'phone_number' => '08031234567', 'gender' => 'female', 'date_of_birth' => '2007-05-06', 'nationality' => 'Nigerian',
        'state_of_origin' => 'Ondo', 'lga' => 'Ondo West', 'address' => '1 Palace Road, Ondo',
        'guardian_name' => 'Mrs Adeyemi', 'guardian_relationship' => 'Mother', 'guardian_phone' => '08030000000',
    ])->assertSessionHasNoErrors();
    foreach (['birth_certificate', 'state_of_origin'] as $type) {
        test()->post(route('my.onboarding.documents.store'), ['type' => $type, 'file' => UploadedFile::fake()->create("$type.pdf", 50, 'application/pdf')])->assertSessionHasNoErrors();
    }
    test()->actingAs(test()->admin)->post(route('verification.verify', $student->id))->assertSessionHas('success');
}

it('admits without a matric number by default and logs the student in with the application number', function () {
    $student = applicantStudent();

    expect($student->matric_number)->toBeNull()
        ->and($student->matric_label)->toBe('Matric number not yet issued')
        ->and($student->user->username)->toBe($student->applicant->application_no)
        ->and($student->clearances()->count())->toBeGreaterThan(0);
});

it('clears the automatic items by themselves and issues the matric number when the last one clears', function () {
    $student = applicantStudent();
    getVerified($student);

    $student = $student->fresh();
    expect($student->cleared_at)->not->toBeNull()
        ->and($student->matric_number)->toMatch('#^2026/BED-BIO/0001$#')
        ->and($student->user->username)->toBe($student->matric_number)
        ->and($student->user->notifications()->count())->toBeGreaterThan(0)
        ->and(\App\Models\MatricEvent::where('student_id', $student->id)->value('action'))->toBe('generated');
});

it('waits for a manual item and for every required item', function () {
    ClearanceItem::where('code', 'medical')->update(['is_active' => true]);
    $student = applicantStudent();
    getVerified($student);

    expect($student->fresh()->matric_number)->toBeNull()->and($student->fresh()->cleared_at)->toBeNull();

    $item = ClearanceItem::where('code', 'medical')->first();
    $this->actingAs($this->admin)->post(route('clearance.decide', [$student->id, $item->id]), ['status' => 'cleared'])->assertSessionHas('success');

    expect($student->fresh()->cleared_at)->not->toBeNull()->and($student->fresh()->matric_number)->not->toBeNull();
});

it('does not issue the matric number automatically when the university prefers a batch', function () {
    Setting::put('matric.auto_issue', '0');
    $student = applicantStudent();
    getVerified($student);

    expect($student->fresh()->cleared_at)->not->toBeNull()->and($student->fresh()->matric_number)->toBeNull();
});

it('issues the matric number at admission when the university is set that way', function () {
    Setting::put('matric.issue_at', 'admission');
    $student = applicantStudent();

    expect($student->matric_number)->not->toBeNull()->and($student->user->username)->toBe($student->matric_number);
});

it('lets only the right office sign a manual item and demands a reason to reject', function () {
    Role::firstOrCreate(['name' => 'Health Centre', 'guard_name' => 'web']);
    ClearanceItem::where('code', 'medical')->update(['is_active' => true, 'office_role' => 'Health Centre']);
    ClearanceItem::where('code', 'department')->update(['is_active' => true, 'office_role' => 'Department Head']);
    $student = applicantStudent();
    getVerified($student);
    $medical = ClearanceItem::where('code', 'medical')->first();
    $dept = ClearanceItem::where('code', 'department')->first();

    $nurse = User::factory()->create();
    $nurse->givePermissionTo(['View clearance', 'Clear students']);
    $nurse->assignRole('Health Centre');

    $this->actingAs($nurse)->post(route('clearance.decide', [$student->id, $dept->id]), ['status' => 'cleared'])->assertSessionHas('error', fn ($m) => str_contains($m, 'not allowed'));
    $this->post(route('clearance.decide', [$student->id, $medical->id]), ['status' => 'rejected'])->assertSessionHas('error', fn ($m) => str_contains($m, 'why'));
    $this->post(route('clearance.decide', [$student->id, $medical->id]), ['status' => 'rejected', 'note' => 'Bring your genotype test'])->assertSessionHas('success');

    $row = $student->clearances()->where('clearance_item_id', $medical->id)->first();
    expect($row->status)->toBe('rejected')->and($row->decided_by)->toBe($nurse->id);

    // an automatic item cannot be signed by hand, even by an admin
    $auto = ClearanceItem::where('code', 'biodata')->first();
    $this->actingAs($this->admin)->post(route('clearance.decide', [$student->id, $auto->id]), ['status' => 'rejected', 'note' => 'x'])->assertSessionHas('error');
});

it('shows the student a live checklist and lets them re-submit a rejected item', function () {
    ClearanceItem::where('code', 'medical')->update(['is_active' => true]);
    $student = applicantStudent();
    getVerified($student);
    $medical = ClearanceItem::where('code', 'medical')->first();
    $this->actingAs($this->admin)->post(route('clearance.decide', [$student->id, $medical->id]), ['status' => 'rejected', 'note' => 'Missing genotype result']);

    $this->actingAs($student->user->fresh())->get(route('my.clearance'))
        ->assertOk()->assertSee('Medical clearance')->assertSee('Missing genotype result')->assertSee('Ask for another look');

    $this->post(route('my.clearance.resubmit', $medical->id), ['note' => 'Uploaded at the clinic'])->assertSessionHas('success');
    $row = $student->clearances()->where('clearance_item_id', $medical->id)->first();
    expect($row->status)->toBe('pending')->and($row->student_note)->toBe('Uploaded at the clinic');

    // cannot re-submit what is not rejected
    $this->post(route('my.clearance.resubmit', $medical->id))->assertSessionHas('error');

    // the student was told about the rejection
    expect($student->user->notifications()->get()->pluck('data.title')->implode('|'))->toContain('not cleared');
});

it('prints a clearance slip only once fully cleared', function () {
    $student = applicantStudent();
    $this->actingAs($student->user->fresh())->get(route('my.clearance.slip'))->assertNotFound();

    getVerified($student);
    $this->actingAs($student->user->fresh())->get(route('my.clearance.slip'))->assertOk()->assertSee('Clearance slip')->assertSee($student->fresh()->matric_number);
    $this->actingAs($this->admin)->get(route('clearance.slip', $student->id))->assertOk()->assertSee('Clearance slip');
});

it('clears a manual item for many students at once', function () {
    ClearanceItem::where('code', 'medical')->update(['is_active' => true]);
    $a = applicantStudent('a@example.com', '11111111AA');
    $b = applicantStudent('b@example.com', '22222222BB');
    $medical = ClearanceItem::where('code', 'medical')->first();

    $this->actingAs($this->admin)->post(route('clearance.bulk'), ['item_id' => $medical->id, 'student_ids' => [$a->id, $b->id]])
        ->assertSessionHas('success', fn ($m) => str_contains($m, '2 student(s)'));
    $this->post(route('clearance.bulk'), ['item_id' => $medical->id])->assertSessionHasErrors('student_ids');

    expect($a->clearances()->where('clearance_item_id', $medical->id)->value('status'))->toBe('cleared');
});

it('takes automatic clearance back if the fact stops being true', function () {
    $student = applicantStudent();
    getVerified($student);
    expect($student->fresh()->cleared_at)->not->toBeNull();

    $this->actingAs($this->admin)->post(route('verification.unverify', $student->id));

    $row = $student->clearances()->whereHas('item', fn ($q) => $q->where('code', 'verification'))->first();
    expect($row->status)->toBe('pending')->and($student->fresh()->cleared_at)->toBeNull();
});

it('lets an admin manage the checklist and enforces its permissions', function () {
    $this->actingAs($this->admin)->post(route('academics.clearance-items.store'), [
        'name' => 'Library clearance', 'code' => 'library', 'mode' => 'manual', 'office_role' => '', 'is_required' => 1, 'is_active' => 1, 'sort_order' => 70,
    ])->assertSessionHasNoErrors();
    expect(ClearanceItem::where('code', 'library')->exists())->toBeTrue();

    $this->post(route('academics.clearance-items.store'), ['name' => 'Bad', 'code' => 'bad', 'mode' => 'auto'])->assertSessionHasErrors('auto_rule');

    $plain = User::factory()->create();
    $this->actingAs($plain)->get(route('clearance.index'))->assertForbidden();
    $this->get(route('academics.clearance-items.index'))->assertForbidden();
    $this->post(route('clearance.bulk'), [])->assertForbidden();
});

it('fully clears a student whose fees item depends on payment', function () {
    $tuition = BillItem::create(['name' => 'Tuition', 'code' => 'TUI']);
    Bill::create(['academic_session_id' => $this->session->id, 'programme_id' => $this->programme->id, 'level' => 100, 'bill_item_id' => $tuition->id, 'student_type' => 'fresh', 'amount' => 50000]);
    ClearanceItem::where('code', 'school_fees')->update(['is_active' => true]);

    $student = applicantStudent();
    getVerified($student);
    expect($student->fresh()->cleared_at)->toBeNull();

    $invoice = app(InvoiceService::class)->generateForStudent($student->fresh(), $this->session);
    app(InvoiceService::class)->recordOfflinePayment($invoice, 50000, 'cash', null, null, null, $this->admin->id);

    expect($student->fresh()->cleared_at)->not->toBeNull()->and($student->fresh()->matric_number)->not->toBeNull();
});
