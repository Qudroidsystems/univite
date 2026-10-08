<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Programme;
use App\Services\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices)
    {
        $this->middleware('permission:View invoices')->only(['index', 'show', 'receipt']);
        $this->middleware('permission:Generate invoices')->only('generate');
        $this->middleware('permission:Record offline payments')->only('recordPayment');
        $this->middleware('permission:Cancel invoices')->only('cancel');
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        $invoices = Invoice::with(['payable', 'session'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(fn ($w) => $w->where('reference', 'like', "%{$q}%")
                    ->orWhereHasMorph('payable', [\App\Models\Student::class, \App\Models\Applicant::class], function ($s, $type) use ($q) {
                        $s->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")
                            ->orWhere($type === \App\Models\Student::class ? 'matric_number' : 'application_no', 'like', "%{$q}%");
                    }));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->filled('academic_session_id'), fn ($query) => $query->where('academic_session_id', $request->query('academic_session_id')))
            ->latest('id')->paginate(25)->withQueryString();

        $summary = Invoice::where('status', '!=', 'cancelled')
            ->when($request->filled('academic_session_id'), fn ($query) => $query->where('academic_session_id', $request->query('academic_session_id')))
            ->selectRaw('COALESCE(SUM(total),0) as billed, COALESCE(SUM(amount_paid),0) as paid')->first();

        return view('invoices.index', [
            'pagetitle' => 'Invoices & Payments',
            'invoices'  => $invoices,
            'sessions'  => AcademicSession::orderByDesc('name')->pluck('name', 'id')->all(),
            'programmes' => Programme::orderBy('name')->pluck('name', 'id')->all(),
            'billed'    => (float) $summary->billed,
            'paid'      => (float) $summary->paid,
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['payable.programme', 'session', 'lines', 'payments.recorder']);

        return view('invoices.show', ['pagetitle' => $invoice->reference, 'invoice' => $invoice]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'programme_id'        => ['nullable', 'exists:programmes,id'],
            'level'               => ['nullable', 'integer', 'min:100', 'max:800'],
        ]);

        $r = $this->invoices->generateBulk(AcademicSession::findOrFail($data['academic_session_id']), $data['programme_id'] ?? null, $data['level'] ?? null, auth()->id());

        $message = "{$r['created']} invoice(s) created.";
        if ($r['existing']) {
            $message .= " {$r['existing']} student(s) already had one.";
        }
        if ($r['no_bills']) {
            $message .= " {$r['no_bills']} student(s) skipped because no school bills are set for their programme and level in that session.";
        }

        return back()->with($r['created'] || $r['existing'] ? 'success' : 'error', $r['created'] || $r['existing'] ? $message : ($r['no_bills'] ? $message : 'There are no active students to invoice.'));
    }

    public function recordPayment(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount'            => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'channel'           => ['required', Rule::in(array_keys(Payment::CHANNELS))],
            'gateway_reference' => ['nullable', 'string', 'max:100', Rule::unique('payments', 'gateway_reference')->where('gateway', 'manual')],
            'paid_on'           => ['nullable', 'date', 'before_or_equal:today'],
            'note'              => ['nullable', 'string', 'max:500'],
        ], ['gateway_reference.unique' => 'A payment with this teller / receipt number has already been recorded.']);

        try {
            $payment = $this->invoices->recordOfflinePayment($invoice, (float) $data['amount'], $data['channel'], $data['gateway_reference'] ?? null, $data['paid_on'] ?? null, $data['note'] ?? null, auth()->id());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment of ₦' . number_format((float) $payment->amount, 2) . ' recorded. Reference ' . $payment->reference . '.');
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        try {
            $this->invoices->cancel($invoice);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Invoice cancelled.');
    }

    public function receipt(Payment $payment): View
    {
        abort_unless($payment->status === 'successful', 404);

        return view('invoices.receipt', ['pagetitle' => 'Receipt', 'payment' => $payment->load('invoice.payable.programme', 'invoice.session', 'recorder')]);
    }
}
