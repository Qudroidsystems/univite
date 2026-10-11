<?php

namespace App\Http\Controllers;

use App\Data\Fees\InvoiceTarget;
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
        $this->middleware('permission:Generate invoices')->only('generate', 'preview');
        $this->middleware('permission:Reverse payments')->only('reverse');
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

    /** What a bulk run would do, before anything is created. */
    public function preview(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'programme_id'        => ['nullable', 'exists:programmes,id'],
            'level'               => ['nullable', 'integer', 'min:100', 'max:800'],
            'semester_type_id'    => ['nullable', 'exists:semester_types,id'],
        ]);
        $session = AcademicSession::findOrFail($data['academic_session_id']);

        return view('invoices.generate-preview', [
            'pagetitle' => 'Generate invoices', 'data' => $data, 'session' => $session,
            'programme' => isset($data['programme_id']) ? \App\Models\Programme::find($data['programme_id']) : null,
            'semester' => isset($data['semester_type_id']) ? \App\Models\SemesterType::find($data['semester_type_id']) : null,
            'preview' => $this->invoices->previewBulk(InvoiceTarget::fromArray($data)),
        ]);
    }

    /** Open the reversal preview of a payment. */
    public function reverse(Payment $payment): RedirectResponse
    {
        $op = \App\Support\Reversals::originOf('payment_record', 'Payment', $payment->id);
        if (! $op || $payment->status !== 'successful') {
            return back()->with('error', 'This payment cannot be reversed' . ($payment->status === 'reversed' ? ': it already has been.' : '.'));
        }

        return redirect()->route('operations.reverse', $op);
    }

    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'programme_id'        => ['nullable', 'exists:programmes,id'],
            'level'               => ['nullable', 'integer', 'min:100', 'max:800'],
            'semester_type_id'    => ['nullable', 'exists:semester_types,id'],
        ]);

        $r = $this->invoices->generateBulk(InvoiceTarget::fromArray($data), $request->user());

        return back()->with($r->succeeded() ? 'success' : 'error', $r->message());
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
        abort_unless(in_array($payment->status, ['successful', 'reversed'], true), 404);

        return view('invoices.receipt', ['pagetitle' => 'Receipt', 'payment' => $payment->load('invoice.payable.programme', 'invoice.session', 'recorder')]);
    }
}
