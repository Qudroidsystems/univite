<?php

namespace App\Reversals;

use App\Models\Invoice;
use App\Models\OperationBatch;
use App\Models\User;
use App\Services\InvoiceService;

/** Cancel the invoices a bulk run created. An invoice with any payment on it is kept. */
class InvoiceBatchReversal extends BaseReversal
{
    public function title(OperationBatch $op): string
    {
        return 'Take back the invoices this run created';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $user->can('Cancel invoices');
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('cancel', ['cancel' => ['Cancel invoices', 'Cancel every invoice from this run that nobody has paid anything on. Cancelled invoices stay on record.']]);
        foreach ($op->events()->where('subject_type', 'Invoice')->get() as $e) {
            $invoice = Invoice::with('payable')->find($e->subject_id);
            $label = $e->label ?? 'Invoice #' . $e->subject_id;
            if (! $invoice) {
                $plan->skip($label, null, 'No longer on record.', $e->subject_id);
            } elseif ($invoice->status === 'cancelled') {
                $plan->skip($label, 'Cancelled', 'Already cancelled.', $invoice->id);
            } elseif ((float) $invoice->amount_paid > 0 || $invoice->payments()->whereIn('status', ['successful', 'reversed'])->exists()) {
                $plan->block($label, Invoice::STATUSES[$invoice->status] ?? $invoice->status, 'Payments have been made on it. Reverse the payments first if this invoice is wrong.', $invoice->id);
            } else {
                $plan->change($label . ' · ' . ($invoice->payable?->full_name ?? ''), 'Unpaid ' . self::money((float) $invoice->total), 'Cancelled', $invoice->id);
            }
        }
        $plan->outcome = $plan->counts()['change'] . ' unpaid invoice(s) will be cancelled. They stay on record as cancelled; the run can then be repeated with the right bills.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        $new = $this->begin($op, 'invoice_cancel_batch', $by, $reason);
        $done = 0;
        foreach ($plan->changes() as $item) {
            $invoice = Invoice::find($item['key']);
            app(InvoiceService::class)->cancel($invoice);
            $this->audit->record($new, 'invoice_cancelled', $invoice, ['status' => 'unpaid'], ['status' => 'cancelled'], $reason);
            $done++;
        }
        $c = $plan->counts();
        $this->audit->finish($new, $done, 0, $c['skip'] + $c['blocked']);
        if (! $c['blocked']) {
            $this->audit->markReversed($op);
        }

        return $new;
    }
}
