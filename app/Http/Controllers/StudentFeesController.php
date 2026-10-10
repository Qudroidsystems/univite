<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\View\View;

/** A signed-in student's own invoices and receipts. Access is by ownership, not by staff permission. */
class StudentFeesController extends Controller
{
    private function student()
    {
        return auth()->user()->student ?? abort(403, 'This page is for students.');
    }

    public function index(): View
    {
        $student = $this->student();
        $invoices = $student->invoices()->with('session')->where('status', '!=', 'cancelled')->latest('id')->get();

        return view('invoices.my-fees', [
            'pagetitle'  => 'My Fees',
            'student'    => $student,
            'invoices'   => $invoices,
            'owing'      => $invoices->sum(fn ($i) => $i->outstanding),
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $student = $this->student();
        abort_unless($invoice->payable_type === $student::class && $invoice->payable_id === $student->id && $invoice->status !== 'cancelled', 404);

        return view('invoices.my-invoice', ['pagetitle' => $invoice->reference, 'student' => $student, 'invoice' => $invoice->load('session', 'lines', 'payments')]);
    }

    public function receipt(Payment $payment): View
    {
        $student = $this->student();
        $payment->load('invoice.payable.programme', 'invoice.session', 'recorder');
        abort_unless(in_array($payment->status, ['successful', 'reversed'], true) && $payment->invoice->payable_type === $student::class && $payment->invoice->payable_id === $student->id, 404);

        return view('invoices.receipt', ['pagetitle' => 'Receipt', 'payment' => $payment]);
    }
}
