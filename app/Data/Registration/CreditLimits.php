<?php

namespace App\Data\Registration;

/** How many units a student may register in a semester. */
final readonly class CreditLimits
{
    public function __construct(
        public int $min,
        public int $max,
        public bool $carryoversExempt = false,
        public bool $probation = false,
    ) {
    }
}
