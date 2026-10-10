<?php

namespace App\Reversals;

use App\Models\OperationBatch;
use App\Models\User;
use App\Services\SessionCloneService;

/** Remove what a session copy added. Anything used since (a bill switched on, a sheet started ...) is kept. */
class SessionCloneReversal extends BaseReversal
{
    public function title(OperationBatch $op): string
    {
        return 'Take back this session copy';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $user->can('Undo session copies');
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('undo', ['undo' => ['Undo', 'Remove the copied bills and lecturer assignments and restore the replaced registration windows.']]);
        foreach (app(SessionCloneService::class)->undoPreview($op) as $row) {
            $row['why']
                ? $plan->block($row['label'], 'Copied', $row['why'], $row['item']->id)
                : $plan->change($row['label'], 'Copied', $row['item']->category === 'windows' ? 'Previous window restored' : 'Removed', $row['item']->id);
        }
        $plan->outcome = 'Only setup that nothing has used is removed. Registrations, scores, results and payments are never touched.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        return app(SessionCloneService::class)->undo($op, $by, $reason)['batch'];
    }
}
