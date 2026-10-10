<?php

use App\Mail\DocumentMail;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Department;
use App\Models\DocumentEmail;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\MatricEvent;
use App\Models\OperationBatch;
use App\Models\OperationEvent;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\RegistrationForm;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Reversals\MatricBatchReversal;
use App\Services\AdmissionService;
use App\Services\InvoiceService;
use App\Support\Institution;
use App\Support\Reversals;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    Setting::put('matric.auto_issue', '0');
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $this->programme = Programme::create(['department_id' => $dept->id, 'name' => 'B.Ed. Biology', 'code' => 'BED-BIO', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    Bill::create(['academic_session_id' => $this->session->id, 'programme_id' => $this->programme->id, 'level' => 100,
        'bill_item_id' => BillItem::create(['name' => 'Tuition', 'code' => 'TUI'])->id, 'student_type' => 'all', 'amount' => 50000]);
});

function ocStudent(string $last, string $jamb, bool $cleared = true): Student
{
    $a = Applicant::create([
        'first_name' => 'Ada', 'last_name' => $last, 'email' => strtolower($last) . '@example.com', 'jamb_reg_no' => $jamb,
        'programme_id' => test()->programme->id, 'academic_session_id' => test()->session->id, 'entry_mode' => 'utme',
        'entry_level' => 100, 'status' => 'pending', 'password' => 'secret-pass',
    ]);
    $a->update(['application_no' => 'APP/2026/' . str_pad((string) $a->id, 4, '0', STR_PAD_LEFT)]);
    $s = app(AdmissionService::class)->admit($a->fresh())[0];
    if ($cleared) {
        $s->update(['cleared_at' => now()]);
    }

    return $s->fresh();
}

function ocPay(Invoice $invoice, float $amount, string $teller = 'TLR-1'): void
{
    test()->actingAs(test()->admin)->post(route('invoices.payments.store', $invoice->id), ['amount' => $amount, 'channel' => 'bank_deposit', 'gateway_reference' => $teller])->assertSessionHas('success');
}

// ---- emails ------------------------------------------------------------------------------------------

it('emails the receipt when a payment is recorded, logs it, and respects the on/off setting', function () {
    Mail::fake();
    Institution::put('name', 'Adeyemi Federal University of Education');
    $s = ocStudent('Abe', '11111111AA');
    $invoice = app(InvoiceService::class)->generateForStudent($s, $this->session);
    ocPay($invoice, 20000);
    $p = Payment::firstOrFail();

    Mail::assertSent(DocumentMail::class, fn ($m) => $m->hasTo('abe@example.com') && str_contains($m->subjectLine, $p->reference)
        && str_contains($m->body, 'Official payment receipt') && str_contains($m->body, 'Adeyemi Federal University of Education')
        && str_contains($m->body, '₦20,000.00') && $m->fileName === "Receipt-{$p->reference}.html");
    expect(DocumentEmail::where('type', 'receipt')->where('status', 'sent')->where('subject_id', $p->id)->exists())->toBeTrue();
    // the payment itself is an operation that can be reversed
    expect(Reversals::originOf('payment_record', 'Payment', $p->id))->not->toBeNull();

    Setting::put('mail.documents.receipt', '0');
    Mail::fake();
    ocPay($invoice, 1000, 'TLR-2');
    Mail::assertNothingSent();
});

it('records the payment even when the email cannot be sent, and lists the failure', function () {
    config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
    $s = ocStudent('Abe', '11111111AA');
    ocPay(app(InvoiceService::class)->generateForStudent($s, $this->session), 20000);

    expect(Payment::where('status', 'successful')->count())->toBe(1)
        ->and(DocumentEmail::where('status', 'failed')->count())->toBe(1);
    $this->get(route('emails.index'))->assertOk()->assertSee('failed in the last 7 days')->assertSee('abe@example.com');
});

it('emails the course form when it is submitted and again when it is approved', function () {
    Mail::fake();
    $s = ocStudent('Abe', '11111111AA');
    $form = RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => \App\Models\SemesterType::first()->id, 'level' => 100, 'status' => 'submitted', 'total_units' => 0]);
    app(\App\Services\RegistrationService::class)->decide($form, true, $this->admin->id);

    Mail::assertSent(DocumentMail::class, fn ($m) => $m->hasTo('abe@example.com') && str_contains($m->subjectLine, 'Approved course form') && str_contains($m->body, 'Course registration form'));
    $approve = OperationBatch::where('type', 'registration_approve')->firstOrFail();

    // reversing the approval sends it back to the approval queue
    confirmReversal($this->actingAs($this->admin), $approve, null, 'Approved by mistake before the adviser checked it.')->assertSessionHas('success');
    expect($form->fresh()->status)->toBe('submitted');

    // "email me a copy" from the student's own form
    $form->update(['status' => 'approved']);
    Mail::fake();
    $this->actingAs($s->user->fresh())->post(route('my.documents.email', ['course_form', $form->id]))->assertSessionHas('success');
    Mail::assertSent(DocumentMail::class, 1);
    $other = ocStudent('Bola', '22222222BB');
    $this->actingAs($other->user->fresh())->post(route('my.documents.email', ['course_form', $form->id]))->assertNotFound();
});

it('emails the application slip on submission and the matric number when issued', function () {
    Mail::fake();
    $s = ocStudent('Abe', '11111111AA');
    app(\App\Services\ClearanceService::class)->issueMatric($s, $this->admin);
    Mail::assertSent(DocumentMail::class, fn ($m) => $m->hasTo('abe@example.com') && str_contains($m->body, $s->fresh()->matric_number));

    $mailer = app(\App\Services\DocumentMailer::class);
    $applicant = Applicant::create(['first_name' => 'Kemi', 'last_name' => 'Ade', 'email' => 'kemi@example.com', 'programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'application_no' => 'APP/2026/0099']);
    $mailer->applicationSlip($applicant);
    Mail::assertSent(DocumentMail::class, fn ($m) => $m->hasTo('kemi@example.com') && str_contains($m->body, 'APP/2026/0099') && $m->fileName === 'Application-slip-APP-2026-0099.html');
});

it('saves email settings with an encrypted password and protects the email pages', function () {
    $this->actingAs($this->admin)->put(route('emails.settings'), ['documents' => ['receipt', 'course_form'], 'mailer' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'portal', 'password' => 's3cret-pass', 'from_address' => 'portal@example.com'])
        ->assertSessionHas('success');
    expect(Setting::get('mail.documents.receipt'))->toBe('1')->and(Setting::get('mail.documents.clearance_slip'))->toBe('0')
        ->and(Setting::get('mail.password'))->not->toBe('s3cret-pass')->and(\App\Support\MailSettings::get('password'))->toBe('s3cret-pass');
    \App\Support\MailSettings::apply();
    expect(config('mail.default'))->toBe('smtp')->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')->and(config('mail.from.address'))->toBe('portal@example.com');

    $this->put(route('emails.settings'), ['mailer' => 'smtp', 'host' => ''])->assertSessionHasErrors('host');
    $nobody = User::factory()->create();
    $this->actingAs($nobody)->get(route('emails.index'))->assertForbidden();
    $this->put(route('emails.settings'), [])->assertForbidden();
});

// ---- matric numbers: preview, take back, correct ------------------------------------------------------

it('previews a matric batch before issuing it', function () {
    $a = ocStudent('Abe', '11111111AA');
    $b = ocStudent('Bola', '22222222BB');
    $this->actingAs($this->admin)->get(route('matric.generate.preview'))->assertOk()->assertSee('2026/BED-BIO/0001')->assertSee('2026/BED-BIO/0002')->assertSee('Issue 2 number(s)');
    expect($a->fresh()->matric_number)->toBeNull();
});

it('blocks taking back numbers already in use and corrects them instead, keeping the old ones on record', function () {
    Mail::fake();
    $a = ocStudent('Abe', '11111111AA');
    $b = ocStudent('Bola', '22222222BB');
    $this->actingAs($this->admin)->post(route('matric.generate'));
    $op = OperationBatch::where('type', 'matric_generate')->firstOrFail();
    expect($op->events()->count())->toBe(2)->and($op->events()->first()->label)->toContain('2026/BED-BIO/');

    // Abe pays fees afterwards: the number is now printed on a receipt
    $this->travel(1)->minutes();
    ocPay(app(InvoiceService::class)->generateForStudent($a->fresh(), $this->session), 50000);

    $plan = app(MatricBatchReversal::class)->plan($op, 'take_back');
    expect($plan->counts())->toBe(['change' => 1, 'skip' => 0, 'blocked' => 1])
        ->and(collect($plan->items)->firstWhere('outcome', 'blocked')['why'])->toContain('1 receipt');

    // the format was wrong: fix it, then correct the whole batch
    Setting::put(\App\Services\MatricNumberGenerator::SETTING_KEY, 'AFUED/{SHORT_YEAR}/{PROGRAMME_CODE}/{SEQ:3}');
    $this->get(route('operations.reverse', [$op, 'mode' => 'correct']))->assertOk()->assertSee('AFUED/26/BED-BIO/001')->assertSee('AFUED/26/BED-BIO/002');

    // a stale preview is refused
    $stale = app(MatricBatchReversal::class)->plan($op, 'correct')->fingerprint();
    $c = ocStudent('Chi', '33333333CC');
    $this->post(route('matric.special', $c->id), ['matric_number' => 'AFUED/26/BED-BIO/001']);
    $this->post(route('operations.reverse.run', $op), ['mode' => 'correct', 'fingerprint' => $stale, 'reason' => 'Wrong matric format used.', 'confirm' => 1])
        ->assertRedirect(route('operations.reverse', [$op, 'mode' => 'correct']))->assertSessionHas('error', fn ($m) => str_contains($m, 'changed after the preview'));

    confirmReversal($this, $op, 'correct', 'Wrong matric format used.')->assertSessionHas('success');
    expect($a->fresh()->matric_number)->toBe('AFUED/26/BED-BIO/002')->and($b->fresh()->matric_number)->toBe('AFUED/26/BED-BIO/003')
        ->and($op->fresh()->status)->toBe('corrected')
        ->and(MatricEvent::where('action', 'superseded')->pluck('matric_number')->all())->toBe(['2026/BED-BIO/0001', '2026/BED-BIO/0002'])
        ->and($a->fresh()->user->username)->toBe('AFUED/26/BED-BIO/002');
    $correction = OperationBatch::where('type', 'matric_correct')->firstOrFail();
    expect($correction->related_operation_id)->toBe($op->id)->and($correction->reason)->toBe('Wrong matric format used.')
        ->and($correction->events()->first()->before)->toBe(['matric_number' => '2026/BED-BIO/0001']);
    Mail::assertSent(DocumentMail::class, fn ($m) => $m->hasTo('abe@example.com') && str_contains($m->body, 'corrected from 2026/BED-BIO/0001 to AFUED/26/BED-BIO/002'));

    // superseded numbers are never issued again, and the corrected batch cannot be reversed twice
    expect(app(\App\Services\MatricNumberGenerator::class)->taken('2026/BED-BIO/0001'))->toBeTrue();
    $this->get(route('operations.reverse', $op))->assertNotFound();
    $this->get(route('operations.show', $op))->assertOk()->assertSee($correction->operation_id)->assertSee('Matric numbers corrected');
});

// ---- payments ---------------------------------------------------------------------------------------

it('reverses a posted payment formally: the original stays, a reversal entry is added and the balance returns', function () {
    Mail::fake();
    $s = ocStudent('Abe', '11111111AA');
    $invoice = app(InvoiceService::class)->generateForStudent($s, $this->session);
    ocPay($invoice, 50000);
    $p = Payment::firstOrFail();
    expect($invoice->fresh()->status)->toBe('paid');

    $bursar = User::factory()->create();
    $bursar->givePermissionTo('View invoices');
    $this->actingAs($bursar)->get(route('payments.reverse', $p->id))->assertForbidden();
    $bursar->givePermissionTo('Reverse payments');
    $this->actingAs($bursar->fresh())->get(route('payments.reverse', $p->id))->assertRedirect();
    $op = Reversals::originOf('payment_record', 'Payment', $p->id);
    $this->get(route('operations.reverse', $op))->assertOk()->assertSee($p->reference)->assertSee('₦50,000.00');

    // confirmation needs the tick box and a real reason
    $fp = Reversals::for($op)->plan($op)->fingerprint();
    $this->post(route('operations.reverse.run', $op), ['mode' => 'reverse', 'fingerprint' => $fp, 'reason' => 'Teller was fake'])->assertSessionHasErrors('confirm');
    $this->post(route('operations.reverse.run', $op), ['mode' => 'reverse', 'fingerprint' => $fp, 'reason' => 'short', 'confirm' => 1])->assertSessionHasErrors('reason');
    confirmReversal($this, $op, null, 'The bank reported the teller as fake.')->assertRedirect();

    $p->refresh();
    $rev = Payment::where('reverses_payment_id', $p->id)->firstOrFail();
    expect($p->status)->toBe('reversed')->and((float) $p->amount)->toBe(50000.0)->and($p->reversal_reason)->toBe('The bank reported the teller as fake.')
        ->and((float) $rev->amount)->toBe(-50000.0)->and($rev->status)->toBe('reversal')
        ->and($invoice->fresh()->status)->toBe('unpaid')->and($invoice->fresh()->outstanding)->toBe(50000.0)
        ->and($op->fresh()->status)->toBe('reversed');
    Mail::assertSent(DocumentMail::class, fn ($m) => $m->hasTo('abe@example.com') && str_contains($m->body, 'no longer valid'));

    // the receipt is still there, marked reversed; it cannot be reversed twice
    $this->actingAs($this->admin)->get(route('payments.receipt', $p->id))->assertOk()->assertSee('REVERSED');
    $this->get(route('invoices.show', $invoice->id))->assertSee('Reversal entry')->assertSee('Reversed');
    $this->get(route('operations.reverse', $op))->assertNotFound();
    expect(fn () => app(InvoiceService::class)->reversePayment($p, 'again', $this->admin))->toThrow(RuntimeException::class);
});

it('cancels the unpaid invoices of a bulk run and keeps any that were paid', function () {
    $a = ocStudent('Abe', '11111111AA');
    $b = ocStudent('Bola', '22222222BB');
    $a->update(['current_level' => 100]);
    $b->update(['current_level' => 100]);
    $this->actingAs($this->admin)->post(route('invoices.generate'), ['academic_session_id' => $this->session->id])->assertSessionHas('success');
    $op = OperationBatch::where('type', 'invoice_generate')->firstOrFail();
    expect($op->events()->count())->toBe(2);
    ocPay(Invoice::where('payable_id', $a->id)->first(), 1000);

    $plan = Reversals::for($op)->plan($op);
    expect($plan->counts())->toBe(['change' => 1, 'skip' => 0, 'blocked' => 1]);
    confirmReversal($this, $op, null, 'Generated with last session\'s bills.')->assertSessionHas('success');
    expect(Invoice::where('payable_id', $b->id)->value('status'))->toBe('cancelled')
        ->and(Invoice::where('payable_id', $a->id)->value('status'))->toBe('partial')
        ->and($op->fresh()->status)->toBe('completed');   // not fully reversed: one invoice is kept
});

// ---- settings changes ---------------------------------------------------------------------------------

it('puts back academic rules and institution values, but not once they changed again', function () {
    $this->actingAs($this->admin)->put(route('academics.rules.update'), ['scope' => 'programme', 'id' => $this->programme->id, 'rules' => ['max_units' => 30]])->assertSessionHas('success');
    $op = OperationBatch::where('type', 'rules_change')->firstOrFail();
    expect($op->events()->first()->after['rules'])->toBe(['max_units' => 30]);
    confirmReversal($this, $op, null, 'Max units raised by mistake.')->assertSessionHas('success');
    expect(app(\App\Support\AcademicRules::class)->own('programme', $this->programme->id, null, null))->toBe([]);

    $this->put(route('academics.rules.update'), ['scope' => 'programme', 'id' => $this->programme->id, 'rules' => ['max_units' => 26]]);
    $first = OperationBatch::where('type', 'rules_change')->latest('id')->first();
    $this->put(route('academics.rules.update'), ['scope' => 'programme', 'id' => $this->programme->id, 'rules' => ['max_units' => 28]]);
    expect(Reversals::for($first)->plan($first)->canRun())->toBeFalse();

    $this->put(route('institution.update'), ['name' => 'AFUED', 'motto' => 'Knowledge for service']);
    $this->put(route('institution.update'), ['name' => 'AFUED Ondo', 'motto' => 'Knowledge for service']);
    $op = OperationBatch::where('type', 'institution_update')->latest('id')->first();
    expect($op->events()->count())->toBe(1)->and($op->events()->first()->label)->toBe('Full name of the institution');
    confirmReversal($this, $op, null, 'Name changed without approval.')->assertSessionHas('success');
    expect(Institution::get('name'))->toBe('AFUED')->and(Institution::get('motto'))->toBe('Knowledge for service');
});

// ---- the log itself ---------------------------------------------------------------------------------

it('never edits or deletes the history', function () {
    $s = ocStudent('Abe', '11111111AA');
    ocPay(app(InvoiceService::class)->generateForStudent($s, $this->session), 1000);
    $op = OperationBatch::where('type', 'payment_record')->firstOrFail();

    expect(fn () => $op->delete())->toThrow(LogicException::class)
        ->and(fn () => $op->update(['reason' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $op->events()->first()->update(['note' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => OperationEvent::first()->delete())->toThrow(LogicException::class);
    $op = $op->fresh();
    $op->update(['status' => 'partial']);   // only the outcome may change
    expect($op->fresh()->status)->toBe('partial');

    $ref = Payment::first()->reference;
    $this->actingAs($this->admin)->get(route('operations.index', ['q' => $ref]))->assertOk()->assertSee($op->operation_id)->assertSee('Payment recorded');
});

it('copies only setup into a new session, never registrations, results or payments', function () {
    $s = ocStudent('Abe', '11111111AA');
    ocPay(app(InvoiceService::class)->generateForStudent($s, $this->session), 1000);
    RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => \App\Models\SemesterType::first()->id, 'level' => 100, 'status' => 'approved', 'total_units' => 0]);
    $next = AcademicSession::create(['name' => '2027/2028']);
    $before = [Invoice::count(), Payment::count(), RegistrationForm::count(), \App\Models\CourseResult::count()];

    app(\App\Services\SessionCloneService::class)->run($this->session, $next, ['bills', 'assignments', 'windows'], $this->admin);

    expect(Bill::where('academic_session_id', $next->id)->count())->toBe(1)
        ->and([Invoice::count(), Payment::count(), RegistrationForm::count(), \App\Models\CourseResult::count()])->toBe($before)
        ->and(Invoice::where('academic_session_id', $next->id)->exists())->toBeFalse();
});
