<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\ApiInvoice;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A student's invoices, payments and receipts. Paying opens the portal's pay page (pay_url) in the browser. */
class FeesController extends ApiController
{
    public function index(Request $request, \App\Services\Fees\FeeAccess $access): JsonResponse
    {
        $invoices = $this->student($request)->invoices()->with('session')->where('status', '!=', 'cancelled')->latest('id')->get();

        return $this->ok($invoices->map(fn ($i) => ApiInvoice::from($i)->toArray())->values()->all(), ['outstanding' => round($invoices->sum(fn ($i) => $i->outstanding), 2),
            // the share of this session's fees paid, and what it opens (registration, examinations, results, the app)
            'standing' => $access->standing($this->student($request))->toArray()]);
    }

    /** GET /api/v1/loans: the student's school loans, and whether (and how much) they may borrow now. */
    public function loans(Request $request, \App\Services\Fees\LoanService $loans): JsonResponse
    {
        $student = $this->student($request);
        $invoice = $loans->invoiceFor($student);

        return $this->ok(\App\Models\StudentLoan::with('session', 'invoice')->where('student_id', $student->id)->latest('id')->get()->map(fn ($l) => [
            'id' => $l->id, 'reference' => $l->reference, 'session' => $l->session?->name, 'invoice' => $l->invoice?->reference, 'status' => $l->status,
            'status_label' => \App\Models\StudentLoan::STATUSES[$l->status] ?? $l->status, 'requested' => (float) $l->amount_requested,
            'approved' => $l->amount_approved !== null ? (float) $l->amount_approved : null, 'to_repay' => (float) $l->amount_due, 'repaid' => (float) $l->amount_repaid,
            'balance' => $l->balance, 'overdue' => $l->overdue(), 'next_instalment' => $l->nextInstalment(), 'instalments' => $l->instalments ?? [], 'note' => $l->decision_note,
        ])->values()->all(), [
            'enabled' => \App\Services\Fees\LoanService::enabled(), 'can_apply' => $loans->cannotApply($student) === null, 'reason' => $loans->cannotApply($student),
            'max_amount' => $invoice ? $loans->maxAmount($invoice) : 0, 'invoice' => $invoice?->reference, 'guarantor_required' => \App\Services\Fees\LoanService::get('require_guarantor') === '1',
            'terms' => \App\Services\Fees\LoanService::get('terms'),
        ]);
    }

    /** POST /api/v1/loans {amount, reason?, guarantor_name, guarantor_phone, guarantor_relationship?, guarantor_address?, accept: true} */
    public function applyLoan(Request $request, \App\Services\Fees\LoanService $loans): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:1'], 'reason' => ['nullable', 'string', 'max:1000'], 'guarantor_name' => ['nullable', 'string', 'max:150'],
            'guarantor_phone' => ['nullable', 'string', 'max:30'], 'guarantor_relationship' => ['nullable', 'string', 'max:50'], 'guarantor_address' => ['nullable', 'string', 'max:255'], 'accept' => ['accepted']]);
        try {
            $loan = $loans->apply($this->student($request), $d);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['amount' => [$e->getMessage()]]], 422);
        }

        return $this->ok(['id' => $loan->id, 'reference' => $loan->reference, 'status' => $loan->status], ['message' => 'Your loan application has been sent to the bursary.'], 201);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $student = $this->student($request);
        abort_unless($invoice->payable_type === $student::class && $invoice->payable_id === $student->id && $invoice->status !== 'cancelled', 404);

        return $this->ok(ApiInvoice::from($invoice->load('session', 'lines', 'payments'), true)->toArray());
    }
}
