<?php

namespace App\Data\Payments;

/** What one pass over the online payments still waiting for confirmation did. */
final readonly class ReconcileResult
{
    public function __construct(public int $confirmed, public int $failed, public int $abandoned, public int $stillPending)
    {
    }

    public function message(): string
    {
        return "{$this->confirmed} confirmed, {$this->failed} failed, {$this->abandoned} abandoned, {$this->stillPending} still waiting.";
    }
}
