<?php

namespace App\Data\Payroll;

/** Income up to $upto (a yearly amount; null = no limit) is taxed at $rate percent. */
final readonly class TaxBand
{
    public function __construct(public ?float $upto, public float $rate)
    {
    }
}
