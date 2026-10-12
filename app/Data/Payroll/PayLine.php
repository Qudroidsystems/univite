<?php

namespace App\Data\Payroll;

/** One line of a payslip: an earning, a deduction or an employer contribution. */
final readonly class PayLine
{
    public function __construct(
        public string $kind,
        public ?string $code,
        public string $name,
        public float $amount,
        public ?int $staffPayItemId = null,
    ) {
    }
}
