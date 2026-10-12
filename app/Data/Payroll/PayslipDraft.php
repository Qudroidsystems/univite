<?php

namespace App\Data\Payroll;

/** A worked-out payslip before it is saved. */
final readonly class PayslipDraft
{
    /**
     * @param  PayLine[]  $lines
     * @param  string[]  $notes  things whoever checks the payroll should look at
     */
    public function __construct(
        public array $lines,
        public float $factor,
        public float $basic,
        public float $gross,
        public float $taxable,
        public float $tax,
        public float $pensionEmployee,
        public float $pensionEmployer,
        public float $totalDeductions,
        public float $net,
        public array $notes = [],
    ) {
    }

    /** @return PayLine[] */
    public function linesOf(string $kind): array
    {
        return array_values(array_filter($this->lines, fn (PayLine $l) => $l->kind === $kind));
    }
}
