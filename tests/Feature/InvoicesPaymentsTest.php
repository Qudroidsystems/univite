<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\InvoiceService;
use App\Services\Payments\Gateways;
use App\Services\Payments\ManualGateway;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    \App\Models\Setting::put('matric.issue_at', 'admission'); // these tests cover the issue-at-admission option; clearance has its own tests
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();

    $faculty = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $dept    = Department::create(['faculty_id' => $faculty->id, 'name' => 'Computer Science', 'code' => 'CSC']);
    $this->programme = Programme::create(['department_id' => $dept->id, 'name' => 'B.Sc. Computer Science', 'code' => 'BSC-CSC', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
    $this->s2025 = AcademicSession::create(['name' => '2025/2026']);
    $this->s2026 = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);

    $this->tuition = BillItem::create(['name' => 'Tuition', 'code' => 'TUI']);
    $this->accept  = BillItem::create(['name' => 'Acceptance fee', 'code' => 'ACC']);
    $this->hostel  = BillItem::create(['name' => 'Hostel', 'code' => 'HOS', 'is_active' => false]);

    $bill = fn ($session, $item, $amount, $type = 'all', $level = 100) => Bill::create([
        'academic_session_id' => $session->id, 'programme_id' => $this->programme->id, 'level' => $level,
        'bill_item_id' => $item->id, 'student_type' => $type, 'amount' => $amount,
    ]);
    $bill($this->s2025, $this->tuition, 50000);
    $bill($this->s2025, $this->accept, 20000, 'fresh');
    $bill($this->s2025, $this->hostel, 99999);             // inactive item: must not be billed
    $bill($this->s2026, $this->tuition, 60000, 'all', 200);
    $bill($this->s2026, $this->accept, 20000, 'fresh', 200); // fresh at 200L: only Direct Entry students
});

function newStudent(string $email = 'ada@example.com', string $mode = 'utme', int $level = 100): Student
{
    $applicant = Applicant::create([
        'first_name' => 'Ada', 'last_name' => 'Okafor', 'email' => $email, 'programme_id' => test()->programme->id,
        'academic_session_id' => test()->s2025->id, 'entry_mode' => $mode, 'entry_level' => $level,
    ]);

    return app(AdmissionService::class)->admit($applicant)[0];
}

it('bills a fresh student for the all + fresh bills and skips inactive bill items', function () {
    $student = newStudent();
    $invoice = app(InvoiceService::class)->generateForStudent($student, $this->s2025);

    expect($invoice->total)->toBe('70000.00')
        ->and($invoice->status)->toBe('unpaid')
        ->and($invoice->reference)->toBe(sprintf('INV-2025-%06d', $invoice->id))
        ->and($invoice->lines->pluck('description')->all())->toBe(['Acceptance fee', 'Tuition']);
});

it('bills a returning student without the fresh-only items, using their current level', function () {
    $student = newStudent();
    $student->update(['current_level' => 200]);

    $invoice = app(InvoiceService::class)->generateForStudent($student, $this->s2026);

    expect($invoice->total)->toBe('60000.00')->and($invoice->lines)->toHaveCount(1);
});

it('does not invoice when no bills are set, and never duplicates an invoice', function () {
    $student = newStudent();
    $service = app(InvoiceService::class);

    expect($service->generateForStudent($student, $this->s2026))->toBeNull(); // no 100L bills in 2026/2027

    $first = $service->generateForStudent($student, $this->s2025);
    expect($service->generateForStudent($student, $this->s2025)->id)->toBe($first->id)
        ->and(Invoice::count())->toBe(1);
});

it('generates invoices in bulk from the admin page and reports what happened', function () {
    newStudent('a@example.com');
    newStudent('b@example.com');
    $suspended = newStudent('c@example.com');
    $suspended->update(['status' => 'suspended']);

    $this->actingAs($this->admin);
    $this->post(route('invoices.generate'), ['academic_session_id' => $this->s2025->id])
        ->assertSessionHas('success', fn ($m) => str_contains($m, '2 invoice(s) created.'));
    expect(Invoice::count())->toBe(2);

    $this->post(route('invoices.generate'), ['academic_session_id' => $this->s2025->id])
        ->assertSessionHas('success', fn ($m) => str_contains($m, '0 invoice(s) created.') && str_contains($m, '2 student(s) already had one'));
    expect(Invoice::count())->toBe(2);

    $this->post(route('invoices.generate'), ['academic_session_id' => $this->s2026->id])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'no school bills match'));

    $this->post(route('invoices.generate'), [])->assertSessionHasErrors('academic_session_id');
});

it('records part payments until the invoice is paid', function () {
    $invoice = app(InvoiceService::class)->generateForStudent(newStudent(), $this->s2025);
    $this->actingAs($this->admin);

    $this->post(route('invoices.payments.store', $invoice->id), ['amount' => 30000, 'channel' => 'bank_deposit', 'gateway_reference' => 'TLR-1'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    $invoice->refresh();
    expect($invoice->status)->toBe('partial')->and($invoice->amount_paid)->toBe('30000.00')->and($invoice->outstanding)->toBe(40000.0);

    $this->post(route('invoices.payments.store', $invoice->id), ['amount' => 40000, 'channel' => 'cash']);
    $invoice->refresh();
    expect($invoice->status)->toBe('paid')->and($invoice->outstanding)->toBe(0.0)->and($invoice->payments)->toHaveCount(2);
});

it('refuses overpayment, duplicate teller numbers, bad amounts and future dates', function () {
    $invoice = app(InvoiceService::class)->generateForStudent(newStudent(), $this->s2025);
    $this->actingAs($this->admin);
    $url = route('invoices.payments.store', $invoice->id);

    $this->post($url, ['amount' => 70000.01, 'channel' => 'cash'])->assertSessionHas('error');
    $this->post($url, ['amount' => 0, 'channel' => 'cash'])->assertSessionHasErrors('amount');
    $this->post($url, ['amount' => 100, 'channel' => 'bitcoin'])->assertSessionHasErrors('channel');
    $this->post($url, ['amount' => 100, 'channel' => 'cash', 'paid_on' => now()->addDay()->toDateString()])->assertSessionHasErrors('paid_on');

    $this->post($url, ['amount' => 100, 'channel' => 'bank_deposit', 'gateway_reference' => 'TLR-9'])->assertSessionHasNoErrors();
    $this->post($url, ['amount' => 100, 'channel' => 'bank_deposit', 'gateway_reference' => 'TLR-9'])->assertSessionHasErrors('gateway_reference');
    expect($invoice->fresh()->amount_paid)->toBe('100.00');
});

it('cancels only invoices without payments, and cancelled invoices accept nothing', function () {
    $service = app(InvoiceService::class);
    $student = newStudent();
    $invoice = $service->generateForStudent($student, $this->s2025);
    $this->actingAs($this->admin);

    $this->post(route('invoices.payments.store', $invoice->id), ['amount' => 100, 'channel' => 'cash']);
    $this->post(route('invoices.cancel', $invoice->id))->assertSessionHas('error');
    expect($invoice->fresh()->status)->toBe('partial');

    $other = newStudent('o@example.com');
    $unpaid = $service->generateForStudent($other, $this->s2025);
    $this->post(route('invoices.cancel', $unpaid->id))->assertSessionHas('success');
    expect($unpaid->fresh()->status)->toBe('cancelled');
    $this->post(route('invoices.payments.store', $unpaid->id), ['amount' => 100, 'channel' => 'cash'])->assertSessionHas('error');

    // a cancelled invoice can be raised again
    expect($service->generateForStudent($other, $this->s2025)->id)->not->toBe($unpaid->id);
});

it('confirms an online payment once even if the gateway reports it twice', function () {
    $service = app(InvoiceService::class);
    $invoice = $service->generateForStudent(newStudent(), $this->s2025);
    $payment = Payment::create(['invoice_id' => $invoice->id, 'reference' => 'PAY-TEST', 'gateway' => 'paystack', 'amount' => 70000, 'status' => 'pending']);

    $service->markSuccessful($payment, 'GW-1');
    $service->markSuccessful($payment, 'GW-1');

    expect($invoice->fresh()->amount_paid)->toBe('70000.00')->and($invoice->fresh()->status)->toBe('paid')
        ->and($payment->fresh()->gateway_reference)->toBe('GW-1');
});

it('exposes the manual gateway and rejects unknown ones', function () {
    expect(Gateways::get('manual'))->toBeInstanceOf(ManualGateway::class)
        ->and(Gateways::online())->toBe([])
        ->and(fn () => Gateways::get('nope'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Gateways::get('manual')->initialize(new Payment))->toThrow(LogicException::class);
});

it('lists, searches and shows invoices and receipts to staff', function () {
    $invoice = app(InvoiceService::class)->generateForStudent(newStudent(), $this->s2025);
    $this->actingAs($this->admin);
    $this->post(route('invoices.payments.store', $invoice->id), ['amount' => 1000, 'channel' => 'cash']);

    $this->get(route('invoices.index'))->assertOk()->assertSee($invoice->reference)->assertSee('₦70,000.00');
    $this->get(route('invoices.index', ['q' => '2025/BSC-CSC/0001']))->assertSee($invoice->reference);
    $this->get(route('invoices.index', ['status' => 'paid']))->assertDontSee($invoice->reference);
    $this->get(route('invoices.show', $invoice->id))->assertOk()->assertSee('Tuition')->assertSee('Record an offline payment');
    $this->get(route('payments.receipt', Payment::first()->id))->assertOk()->assertSee('Official payment receipt')->assertSee('₦1,000.00');
});

it('enforces the invoice permissions separately', function () {
    $invoice = app(InvoiceService::class)->generateForStudent(newStudent(), $this->s2025);
    $clerk = User::factory()->create();
    $clerk->givePermissionTo('View invoices');
    $this->actingAs($clerk);

    $this->get(route('invoices.show', $invoice->id))->assertOk()->assertDontSee('Record an offline payment')->assertDontSee('Cancel invoice');
    $this->post(route('invoices.generate'), ['academic_session_id' => $this->s2025->id])->assertForbidden();
    $this->post(route('invoices.payments.store', $invoice->id), ['amount' => 1, 'channel' => 'cash'])->assertForbidden();
    $this->post(route('invoices.cancel', $invoice->id))->assertForbidden();

    $nobody = User::factory()->create();
    $this->actingAs($nobody)->get(route('invoices.index'))->assertForbidden();
});

it('lets a student see only their own fees and receipts', function () {
    $service = app(InvoiceService::class);
    $ada = newStudent();
    $bola = newStudent('bola@example.com');
    $adaInvoice = $service->generateForStudent($ada, $this->s2025);
    $bolaInvoice = $service->generateForStudent($bola, $this->s2025);
    $adaPayment = $service->recordOfflinePayment($adaInvoice, 1000, 'cash', null, null, null, $this->admin->id);
    $bolaPayment = $service->recordOfflinePayment($bolaInvoice, 2000, 'cash', null, null, null, $this->admin->id);

    $this->actingAs($ada->user);
    $this->get(route('my.fees'))->assertOk()->assertSee($adaInvoice->reference)->assertDontSee($bolaInvoice->reference)->assertSee('₦69,000.00');
    $this->get(route('my.fees.show', $adaInvoice->id))->assertOk()->assertSee('Tuition');
    $this->get(route('my.fees.show', $bolaInvoice->id))->assertNotFound();
    $this->get(route('my.receipt', $adaPayment->id))->assertOk()->assertSee('₦1,000.00');
    $this->get(route('my.receipt', $bolaPayment->id))->assertNotFound();
    $this->get(route('invoices.index'))->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertSee('My Fees');
});

it('keeps the fees pages for students only', function () {
    $this->actingAs($this->admin)->get(route('my.fees'))->assertForbidden();
});
