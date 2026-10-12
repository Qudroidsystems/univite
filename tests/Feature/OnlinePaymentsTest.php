<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\OperationEvent;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\InvoiceService;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Payments\PaymentSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Http::preventStrayRequests();
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();

    $faculty = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Computer Science', 'code' => 'CSC']);
    $this->programme = Programme::create(['department_id' => $dept->id, 'name' => 'B.Sc. Computer Science', 'code' => 'BSC-CSC', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);
    $item = BillItem::create(['name' => 'Tuition', 'code' => 'TUI']);
    Bill::create(['academic_session_id' => $this->session->id, 'programme_id' => $this->programme->id, 'level' => 100, 'bill_item_id' => $item->id, 'student_type' => 'all', 'amount' => 50000]);

    $this->student = payingStudent();
    $this->invoice = app(InvoiceService::class)->generateForStudent($this->student, $this->session);
});

function payingStudent(string $email = 'ada@example.com'): Student
{
    $applicant = Applicant::create([
        'first_name' => 'Ada', 'last_name' => 'Okafor', 'email' => $email, 'programme_id' => test()->programme->id,
        'academic_session_id' => test()->session->id, 'entry_mode' => 'utme', 'entry_level' => 100,
    ]);

    return app(AdmissionService::class)->admit($applicant)->student;
}

function gatewayOn(string $name, array $values = []): void
{
    app(PaymentSettings::class)->save(array_merge(match ($name) {
        'paystack' => ['paystack_enabled' => '1', 'paystack_secret_key' => 'sk_test_abc'],
        'flutterwave' => ['flutterwave_enabled' => '1', 'flutterwave_secret_key' => 'FLWSECK_TEST-abc', 'flutterwave_webhook_hash' => 'my-hash'],
        'remita' => ['remita_enabled' => '1', 'remita_merchant_id' => '2547916', 'remita_api_key' => '1946', 'remita_service_type' => '4430731'],
    }, $values));
}

function paystackVerifies(string $status = 'success', int $kobo = 5000000): void
{
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'abc']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => $status, 'amount' => $kobo, 'currency' => 'NGN', 'id' => 987, 'channel' => 'card', 'gateway_response' => 'Approved']]),
    ]);
}

it('keeps gateway keys encrypted, never shows them again and leaves them out of the audit trail', function () {
    $this->actingAs($this->admin)->put(route('payments.settings.update'), ['abandon_hours' => 72, 'remita_mode' => 'demo', 'paystack_enabled' => '1', 'paystack_secret_key' => 'sk_live_secret123'])
        ->assertSessionHas('success');

    expect(Setting::get('payments.paystack_secret_key'))->not->toContain('sk_live_secret123')
        ->and(app(PaymentSettings::class)->get('paystack_secret_key'))->toBe('sk_live_secret123')
        ->and(OperationEvent::all()->toJson())->not->toContain('sk_live_secret123')
        ->and(app(OnlinePaymentService::class)->choices())->toHaveKey('paystack');

    $this->get(route('payments.settings'))->assertOk()->assertDontSee('sk_live_secret123')->assertSee('Saved. Leave empty to keep it.')->assertSee('Live for payers');

    // Saving again with the box empty keeps the key.
    $this->put(route('payments.settings.update'), ['abandon_hours' => 72, 'remita_mode' => 'demo', 'paystack_secret_key' => ''])->assertSessionHas('success');
    expect(app(PaymentSettings::class)->get('paystack_secret_key'))->toBe('sk_live_secret123');

    // Switched on without keys: saved, with a warning, and payers do not see it.
    $this->put(route('payments.settings.update'), ['abandon_hours' => 72, 'remita_mode' => 'demo', 'remita_enabled' => '1'])->assertSessionHas('warning');
    expect(app(OnlinePaymentService::class)->choices())->not->toHaveKey('remita');
});

it('only lets bursary staff with the permission open payment settings', function () {
    $this->actingAs($this->student->user)->get(route('payments.settings'))->assertForbidden();
});

it('tells the student when online payment is not set up', function () {
    $this->actingAs($this->student->user)->get(route('my.fees.show', $this->invoice))->assertOk()->assertSee('Online payment is not set up yet');
});

it('takes a student to Paystack and counts the payment once Paystack confirms it', function () {
    gatewayOn('paystack');
    paystackVerifies();

    $this->actingAs($this->student->user)->get(route('my.fees.show', $this->invoice))->assertSee('Pay online')->assertSee('Paystack');
    $this->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 50000])->assertRedirect('https://checkout.paystack.com/abc');

    $payment = Payment::where('gateway', 'paystack')->sole();
    expect($payment->status)->toBe('pending')->and($payment->amount)->toBe('50000.00');
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/transaction/initialize') && $r['amount'] === 5000000 && $r['reference'] === $payment->reference
        && $r['email'] === 'ada@example.com' && $r['callback_url'] === route('payments.return', $payment->reference) && $r->hasHeader('Authorization', 'Bearer sk_test_abc'));

    $this->get(route('payments.return', $payment->reference))->assertRedirect(route('my.fees.show', $this->invoice))->assertSessionHas('success');
    expect($payment->fresh()->status)->toBe('successful')->and($payment->fresh()->gateway_reference)->toBe('987')->and($payment->fresh()->channel)->toBe('card')
        ->and($this->invoice->fresh()->status)->toBe('paid');

    // Coming back twice (or the webhook arriving too) does not count it twice.
    $this->get(route('payments.return', $payment->reference));
    expect($this->invoice->fresh()->amount_paid)->toBe('50000.00');
});

it('does not count a payment the gateway says was for less than asked', function () {
    gatewayOn('paystack');
    paystackVerifies('success', 100000); // ₦1,000 instead of ₦50,000

    $this->actingAs($this->student->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 50000]);
    $payment = Payment::where('gateway', 'paystack')->sole();
    $this->get(route('payments.return', $payment->reference))->assertSessionHas('error');

    expect($payment->fresh()->status)->toBe('failed')->and($payment->fresh()->note)->toContain('₦1,000.00')->and($this->invoice->fresh()->status)->toBe('unpaid');
});

it('leaves an unfinished payment waiting and lets the payer check again', function () {
    gatewayOn('paystack');
    paystackVerifies('abandoned');

    $this->actingAs($this->student->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 50000]);
    $payment = Payment::where('gateway', 'paystack')->sole();
    $this->get(route('payments.return', $payment->reference))->assertSessionHas('warning');

    expect($payment->fresh()->status)->toBe('pending');
    $this->get(route('my.fees.show', $this->invoice))->assertSee('is waiting for confirmation')->assertSee('Check again');
});

it('confirms Paystack webhooks only when the signature is right', function () {
    gatewayOn('paystack');
    paystackVerifies();
    $this->actingAs($this->student->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 50000]);
    $payment = Payment::where('gateway', 'paystack')->sole();
    auth()->logout();

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $payment->reference]]);
    $this->call('POST', route('payments.webhook', 'paystack'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'forged'], $body)->assertOk();
    expect($payment->fresh()->status)->toBe('pending');

    $this->call('POST', route('payments.webhook', 'paystack'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_abc')], $body)->assertOk();
    expect($payment->fresh()->status)->toBe('successful');
});

it('pays with Flutterwave and checks its webhook hash', function () {
    gatewayOn('flutterwave');
    Http::fake([
        'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/xyz']]),
        'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'success', 'data' => ['status' => 'successful', 'amount' => 50000, 'currency' => 'NGN', 'id' => 555, 'payment_type' => 'bank_transfer']]),
    ]);

    $this->actingAs($this->student->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'flutterwave', 'amount' => 50000])->assertRedirect('https://checkout.flutterwave.com/v3/hosted/pay/xyz');
    $payment = Payment::where('gateway', 'flutterwave')->sole();

    $this->postJson(route('payments.webhook', 'flutterwave'), ['data' => ['tx_ref' => $payment->reference]], ['verif-hash' => 'wrong'])->assertOk();
    expect($payment->fresh()->status)->toBe('pending');
    $this->postJson(route('payments.webhook', 'flutterwave'), ['data' => ['tx_ref' => $payment->reference]], ['verif-hash' => 'my-hash'])->assertOk();
    expect($payment->fresh()->status)->toBe('successful')->and($payment->fresh()->channel)->toBe('bank_transfer')->and($this->invoice->fresh()->status)->toBe('paid');
});

it('gets a Remita RRR, posts the payer to Remita, and confirms a bank-branch payment from the notification', function () {
    gatewayOn('remita', ['remita_service_type_fees' => '9999']);
    Http::fake([
        'remitademo.net/remita/exapp/api/v1/send/api/echannelsvc/merchant/api/paymentinit' => Http::response('jsonp ({"statuscode":"025","RRR":"280007021192","status":"Payment Reference generated"})'),
        'remitademo.net/*/status.reg' => Http::response(['status' => '00', 'amount' => 50000, 'RRR' => '280007021192', 'message' => 'Successful', 'channel' => 'BRANCH']),
    ]);

    $this->actingAs($this->student->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'remita', 'amount' => 50000])
        ->assertOk()->assertSee('https://remitademo.net/remita/ecomm/finalize.reg')->assertSee('280007021192')->assertSee(hash('sha512', '2547916' . '280007021192' . '1946'));
    $payment = Payment::where('gateway', 'remita')->sole();
    expect($payment->gateway_reference)->toBe('280007021192');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'paymentinit') && $r['serviceTypeId'] === '9999' && $r['amount'] === '50000.00'
        && $r->hasHeader('Authorization', 'remitaConsumerKey=2547916,remitaConsumerToken=' . hash('sha512', '2547916' . '9999' . $payment->reference . '50000.00' . '1946')));

    $this->postJson(route('payments.webhook', 'remita'), [['rrr' => '280007021192', 'orderRef' => $payment->reference]])->assertOk();
    expect($payment->fresh()->status)->toBe('successful')->and($this->invoice->fresh()->status)->toBe('paid');
});

it('refuses less than the instalment due, more than the balance, closed windows and other students\' invoices', function () {
    gatewayOn('paystack');
    paystackVerifies();
    $this->invoice->update(['installment_plan' => [30000, 20000]]);

    $this->actingAs($this->student->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 10000])->assertSessionHas('error', 'Pay at least ₦30,000.00 now.');
    $this->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 60000])->assertSessionHas('error', fn ($m) => str_contains($m, 'more than'));
    $this->post(route('my.fees.pay', $this->invoice), ['gateway' => 'flutterwave', 'amount' => 30000])->assertSessionHasErrors('gateway');
    $this->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 30000])->assertRedirect('https://checkout.paystack.com/abc');

    $this->invoice->update(['closes_at' => now()->subDay()]);
    $this->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 30000])->assertSessionHas('error', fn ($m) => str_contains($m, 'window closed'));

    $other = payingStudent('bola@example.com');
    $this->actingAs($other->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 30000])->assertNotFound();
});

it('settles an earlier payment the payer finished but never came back from before starting another', function () {
    gatewayOn('paystack');
    paystackVerifies();
    $this->actingAs($this->student->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 50000]);

    $this->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 50000])->assertSessionHas('error', 'This invoice is fully paid.');
    expect(Payment::where('gateway', 'paystack')->count())->toBe(1)->and($this->invoice->fresh()->status)->toBe('paid');
});

it('marks the payment failed and says so when the gateway cannot start it', function () {
    gatewayOn('paystack');
    Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

    $this->actingAs($this->student->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 50000])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'could not be opened'));
    expect(Payment::where('gateway', 'paystack')->sole())->status->toBe('failed')->note->toContain('Invalid key');
});

it('checks waiting payments on the schedule, abandons old ones and still counts one paid late', function () {
    gatewayOn('remita');
    Http::fake([
        '*/paymentinit' => Http::response('jsonp ({"statuscode":"025","RRR":"111","status":"Payment Reference generated"})'),
        '*/status.reg' => Http::sequence()->push(['status' => '021', 'message' => 'Pending'])->push(['status' => '00', 'amount' => 50000]),
    ]);
    $this->actingAs($this->student->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'remita', 'amount' => 50000]);
    $payment = Payment::where('gateway', 'remita')->sole();
    Payment::whereKey($payment->id)->update(['created_at' => now()->subHours(80)]);

    $this->artisan('payments:reconcile')->expectsOutputToContain('1 abandoned')->assertSuccessful();
    expect($payment->fresh()->status)->toBe('abandoned');

    // The payer later pays the RRR at a bank; Remita's notification still counts it.
    $this->postJson(route('payments.webhook', 'remita'), [['rrr' => '111']])->assertOk();
    expect($payment->fresh()->status)->toBe('successful')->and($this->invoice->fresh()->status)->toBe('paid');
});

it('lets staff ask the gateway about a waiting payment from the invoice page', function () {
    gatewayOn('paystack');
    paystackVerifies();
    $this->actingAs($this->student->user)->post(route('my.fees.pay', $this->invoice), ['gateway' => 'paystack', 'amount' => 50000]);
    $payment = Payment::where('gateway', 'paystack')->sole();

    $this->actingAs($this->admin)->get(route('invoices.show', $this->invoice))->assertSee('Check with Paystack');
    $this->post(route('payments.check', $payment))->assertSessionHas('success');
    expect($payment->fresh()->status)->toBe('successful');
});

it('lets an applicant pay the screening fee online', function () {
    gatewayOn('paystack');
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/app', 'access_code' => 'x']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 200000, 'currency' => 'NGN', 'id' => 1]]),
    ]);
    $applicant = Applicant::create(['first_name' => 'Tola', 'last_name' => 'Ade', 'email' => 'tola@example.com', 'programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'status' => 'draft']);
    $invoice = Invoice::create(['kind' => 'screening', 'payable_type' => Applicant::class, 'payable_id' => $applicant->id, 'academic_session_id' => $this->session->id, 'title' => 'Screening fee', 'total' => 2000, 'amount_paid' => 0, 'status' => 'unpaid', 'reference' => 'INV-S-1']);

    $this->withSession(['applicant_id' => $applicant->id])->get(route('apply.dashboard'))->assertSee('Pay online');
    $this->post(route('apply.pay'), ['gateway' => 'paystack', 'amount' => 2000])->assertRedirect('https://checkout.paystack.com/app');
    $payment = Payment::where('gateway', 'paystack')->sole();

    $this->get(route('payments.return', $payment->reference))->assertRedirect(route('apply.dashboard'));
    expect($invoice->fresh()->status)->toBe('paid');
});
