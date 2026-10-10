<?php

namespace App\Reversals;

use App\Models\OperationBatch;
use App\Models\User;
use App\Services\OperationAudit;

abstract class BaseReversal implements Reversal
{
    public function __construct(protected OperationAudit $audit)
    {
    }

    /** Open the reversing operation, linked to the original. */
    protected function begin(OperationBatch $op, string $type, User $by, string $reason, array $meta = []): OperationBatch
    {
        return $this->audit->begin($type, $op->module, $by, $op->academic_session_id, $reason, $meta + ['reverses' => $op->operation_id], $op);
    }

    /** The first recorded subject of an operation (payments, sheets, waivers ... are one record each). */
    protected function subject(OperationBatch $op, string $class): mixed
    {
        $event = $op->events()->where('subject_type', class_basename($class))->first();

        return $event ? $class::find($event->subject_id) : null;
    }

    protected static function money(float $v): string
    {
        return '₦' . number_format($v, 2);
    }
}
