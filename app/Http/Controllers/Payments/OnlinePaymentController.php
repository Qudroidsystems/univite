<?php

namespace App\Http\Controllers\Payments;

use App\Data\Payments\GatewayRedirect;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ApplicationService;
use App\Services\Payments\OnlinePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Students and applicants paying online, the payer coming back from the gateway, and the gateways' webhooks. */
class OnlinePaymentController extends Controller
{
    public function __construct(private OnlinePaymentService $payments)
    {
        $this->middleware('permission:View invoices')->only('check');
    }

    /** A signed-in student pays one of their own invoices. */
    public function student(Request $request, Invoice $invoice): RedirectResponse|Response
    {
        $student = $request->user()->student ?? abort(403, 'This page is for students.');
        abort_unless($invoice->payable_type === $student::class && $invoice->payable_id === $student->id, 404);

        return $this->pay($request, $invoice);
    }

    /** An applicant pays their screening fee. */
    public function applicant(Request $request, ApplicationService $applications): RedirectResponse|Response
    {
        $invoice = $applications->screeningInvoice($request->attributes->get('applicant')) ?? abort(404);

        return $this->pay($request, $invoice);
    }

    private function pay(Request $request, Invoice $invoice): RedirectResponse|Response
    {
        $data = $request->validate([
            'gateway' => ['required', Rule::in(array_keys($this->payments->choices()))],
            'amount'  => ['required', 'numeric', 'gt:0', 'max:999999999999'],
        ], ['gateway.in' => 'Choose how you want to pay.']);

        try {
            $redirect = $this->payments->start($invoice, $data['gateway'], (float) $data['amount'], fn (Payment $p) => route('payments.return', $p->reference));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->send($redirect);
    }

    private function send(GatewayRedirect $redirect): RedirectResponse|Response
    {
        return $redirect->method === 'POST'
            ? response()->view('payments.redirect', ['redirect' => $redirect])
            : redirect()->away($redirect->url);
    }

    /** The gateway sends the payer back here (and the "check again" link comes here): ask the gateway and say what happened. */
    public function back(string $reference): RedirectResponse
    {
        $payment = Payment::where('reference', $reference)->where('gateway', '!=', 'manual')->firstOrFail();
        try {
            $payment = $this->payments->confirm($payment);
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()->to($this->payments->payerPage($payment))->with(...match ($payment->status) {
            'successful' => ['success', 'Payment of ₦' . number_format((float) $payment->amount, 2) . ' received. Your receipt is below and has been emailed to you.'],
            'failed' => ['error', 'The payment did not go through' . ($payment->note ? ': ' . $payment->note : '.') . ' You have not been charged for it. You can try again.'],
            default => ['warning', 'We are still waiting for the gateway to confirm payment ' . $payment->reference . '. If you paid, it will show here once confirmed; you do not need to pay again.'],
        });
    }

    /** Gateways call this when a payment changes. Always answer 200 so they do not keep retrying. */
    public function webhook(Request $request, string $gateway): Response
    {
        abort_unless(array_key_exists($gateway, config('payments.gateways')) && $gateway !== 'manual', 404);
        try {
            $this->payments->webhook($gateway, $request);
        } catch (\Throwable $e) {
            report($e);
        }

        return response('OK');
    }

    /** Staff ask the gateway again about a payment still waiting. */
    public function check(Payment $payment): RedirectResponse
    {
        try {
            $payment = $this->payments->confirm($payment);
        } catch (\Throwable $e) {
            return back()->with('error', 'The gateway could not be reached: ' . $e->getMessage());
        }

        return back()->with($payment->status === 'successful' ? 'success' : 'warning', "Payment {$payment->reference} is " . ($payment->status === 'successful' ? 'confirmed as paid.' : $payment->status . ($payment->note ? ": {$payment->note}" : '.')));
    }
}
