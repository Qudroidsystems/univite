<?php

namespace App\Data\Library;

/** A borrower's position at the desk: what they have out, what they owe, and whether they may borrow more. */
final readonly class BorrowerStanding
{
    public function __construct(
        public int $onLoan,
        public int $limit,
        public int $overdue,
        public float $finesOwed,
        public ?string $blockedBecause,
    ) {
    }

    public function canBorrow(): bool
    {
        return $this->blockedBecause === null;
    }

    /** No books out and nothing owed: what library clearance checks. */
    public function isClear(): bool
    {
        return $this->onLoan === 0 && $this->finesOwed <= 0;
    }
}
