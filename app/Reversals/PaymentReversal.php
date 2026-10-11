<?php

namespace App\Reversals;

use App\Services\OperationAudit;
use App\Models\OperationBatch;
use App\Models\Payment;
use App\Models\RegistrationForm;
use App\Models\Student;
use App\Models\User;
use App\Services\InvoiceService;

/**
 * A posted payment is never deleted or edited. Reversing it adds a reversal entry for the negative amount, marks the
 * original reversed (its receipt then shows that), puts the balance back on the invoice and emails the payer.
 */
class PaymentReversal extends BaseReversal
{
    public function __construct(OperationAudit $audit, private InvoiceService $invoices)
    {
        parent::__construct($audit);
    }

    public function title(OperationBatch $op): string
    {
        return 'Reverse this payment';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $user->can('Reverse payments');
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('reverse', ['reverse' => ['Reverse', 'Record a formal reversal. The original payment and receipt stay on record, marked reversed.']]);
        $payment = $this->subject($op, Payment::class)?->load('invoice.payable');
        if (! $payment) {
            $plan->blockers[] = 'The payment is no longer on record.';

            return $plan;
        }
        if ($payment->status !== 'successful') {
            $plan->blockers[] = 'This payment is ' . $payment->status . ' and cannot be reversed.';

            return $plan;
        }

        $invoice = $payment->invoice;
        $after = (float) $invoice->outstanding + (float) $payment->amount;
        $plan->change($payment->reference . ' · ' . ($invoice->payable?->full_name ?? ''), self::money((float) $payment->amount) . ' paid', 'Reversed; invoice balance becomes ' . self::money($after), $payment->id);

        if ($payment->gateway !== 'manual') {
            $plan->warnings[] = 'This was paid online through ' . ucfirst($payment->gateway) . '. Reversing it here does not refund the money; make the refund through the gateway as well.';
        }
        if ($invoice->payable instanceof Student) {
            $forms = RegistrationForm::where('student_id', $invoice->payable_id)->where('academic_session_id', $invoice->academic_session_id)->whereIn('status', ['submitted', 'approved'])->count();
            if ($forms) {
                $plan->warnings[] = "The student already has {$forms} submitted or approved course form(s) in this session. They are kept, but the student will owe " . self::money($after) . ' again.';
            }
            if ($invoice->payable->needs_onboarding && $invoice->payable->cleared_at) {
                $plan->warnings[] = 'If school fees are a clearance item, the student\'s fees item becomes uncleared again. A matric number already issued is not affected.';
            }
        }
        $plan->outcome = 'A reversal entry of -' . self::money((float) $payment->amount) . ' is added, receipt ' . $payment->reference . ' is marked reversed, and the payer is emailed a reversal notice.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        $payment = Payment::findOrFail($plan->changes()[0]['key']);
        $new = $this->begin($op, 'payment_reverse', $by, $reason, ['invoice' => $payment->invoice?->reference]);
        $reversal = $this->invoices->reversePayment($payment, $reason, $by);
        $this->audit->record($new, 'payment_reversed', $payment->fresh(), ['status' => 'successful'], ['status' => 'reversed'], $reason);
        $this->audit->record($new, 'reversal_entry_added', $reversal, null, ['amount' => (string) $reversal->amount, 'reverses' => $payment->reference]);
        $this->audit->finish($new, 1);
        $this->audit->markReversed($op);

        return $new;
    }
}
