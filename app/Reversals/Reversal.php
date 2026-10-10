<?php

namespace App\Reversals;

use App\Models\OperationBatch;
use App\Models\User;

/** How one kind of operation is reversed or corrected. */
interface Reversal
{
    /** Short title for the button and page, e.g. "Reverse this payment". */
    public function title(OperationBatch $op): string;

    /** Who may reverse it. */
    public function allowed(User $user, OperationBatch $op): bool;

    /** Work out the reversal without changing anything. $mode null = the handler's default. */
    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan;

    /** Carry out the plan (already re-checked) as a new operation linked to the original, and return that operation. */
    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch;
}
