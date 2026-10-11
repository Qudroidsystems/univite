<?php

namespace App\Data\Fees;

/** What a bulk invoice run would do, before anything is created. */
final class BulkInvoicePreview
{
    /** @var array<int, array{0: string, 1: string, 2: float}> name, matric number, amount for the first few students */
    public array $sample = [];

    public int $create = 0;

    public int $existing = 0;

    public int $noBills = 0;

    public float $amount = 0.0;

    public const SAMPLE_SIZE = 15;

    public function add(string $name, string $matric, float $total): void
    {
        $this->create++;
        $this->amount += $total;
        if (count($this->sample) < self::SAMPLE_SIZE) {
            $this->sample[] = [$name, $matric, $total];
        }
    }
}
