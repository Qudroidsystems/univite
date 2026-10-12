<?php

namespace App\Reversals;

use App\Models\OperationBatch;
use App\Models\OperationEvent;
use App\Models\PayrollRun;
use App\Models\StaffPayItem;
use App\Models\User;
use App\Services\OperationAudit;
use Illuminate\Support\Facades\DB;

/** Reopen an approved payroll that has not been paid: loan balances go back up and the payroll returns to draft. */
class PayrollApprovalReversal extends BaseReversal
{
    public function __construct(OperationAudit $audit)
    {
        parent::__construct($audit);
    }

    public function title(OperationBatch $op): string
    {
        return 'Reopen the payroll for ' . (($p = $op->meta['period'] ?? null) ? \Carbon\Carbon::createFromFormat('!Y-m', $p)->format('F Y') : 'this month');
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $user->can('Approve payroll');
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('reopen', ['reopen' => ['Reopen as draft', 'Return the payroll to draft so it can be corrected and approved again. Loan repayments taken by it are put back. The approval stays on the history.']]);
        $run = $this->subject($op, PayrollRun::class);
        if (! $run || $run->status !== 'approved') {
            $plan->blockers[] = $run && $run->status === 'paid' ? 'This payroll has been marked paid, so it can no longer be reopened. Correct any mistake on next month\'s payroll.' : 'The payroll is no longer approved.';

            return $plan;
        }
        if (PayrollRun::where('period', '>', $run->period)->where('status', '!=', 'draft')->exists()) {
            $plan->blockers[] = 'A later month has been approved since. Reopen that one first.';

            return $plan;
        }
        foreach ($op->events()->where('action', 'loan_repaid')->get() as $e) {
            $item = StaffPayItem::find($e->subject_id);
            if (! $item) {
                $plan->skip($e->label ?? 'Loan', null, 'The loan record no longer exists.', $e->id);
            } elseif (round((float) $item->balance, 2) !== round((float) ($e->after['balance'] ?? -1), 2)) {
                $plan->skip($e->label ?? $item->name, self::money((float) $item->balance), 'The balance has changed since; left as it is.', $e->id);
            } else {
                $plan->change($e->label ?? $item->name, 'Balance ' . self::money((float) $item->balance), 'Balance ' . self::money((float) ($e->before['balance'] ?? 0)), $e->id);
            }
        }
        $plan->change($run->label(), 'Approved', 'Draft', 'run');
        $plan->outcome = 'The payroll returns to draft. Staff can no longer see these payslips until it is approved again.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        $new = $this->begin($op, 'payroll_reopen', $by, $reason, ['period' => $op->meta['period'] ?? null]);
        $run = $this->subject($op, PayrollRun::class);
        $n = 0;
        DB::transaction(function () use ($plan, $new, $run, $reason, &$n) {
            foreach ($plan->changes() as $item) {
                if ($item['key'] === 'run') {
                    continue;
                }
                $e = OperationEvent::find($item['key']);
                $loan = StaffPayItem::lockForUpdate()->find($e->subject_id);
                $loan->timestamps = false;
                $loan->update(['balance' => $e->before['balance']]);
                $this->audit->record($new, 'loan_restored', $loan, $e->after, $e->before, $reason, $e->id, $e->label);
                $n++;
            }
            $run->timestamps = false;
            $run->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
            $this->audit->record($new, 'payroll_reopened', $run, ['status' => 'approved'], ['status' => 'draft'], $reason, null, $run->label());
        });
        $this->audit->finish($new, $n + 1, 0, $plan->counts()['skip']);
        $this->audit->markReversed($op);

        return $new;
    }
}
