<?php

namespace App\Data\Leave;

/** One staff member's days of one leave type in one year. */
final readonly class LeaveBalance
{
    public function __construct(
        public int $entitled,
        public float $carried,
        public float $adjusted,
        public int $taken,
        public int $pending,
    ) {
    }

    /** Days still free to apply for: what they are given, less what is taken or waiting for approval. */
    public function available(): float
    {
        return $this->entitled + $this->carried + $this->adjusted - $this->taken - $this->pending;
    }

    /** Entitlement plus carried and adjusted days. */
    public function allowed(): float
    {
        return $this->entitled + $this->carried + $this->adjusted;
    }

    public function toArray(): array
    {
        return ['entitled' => $this->entitled, 'carried' => $this->carried, 'adjusted' => $this->adjusted, 'taken' => $this->taken, 'pending' => $this->pending, 'available' => $this->available()];
    }
}
