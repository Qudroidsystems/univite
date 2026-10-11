<?php

namespace App\Data\Results;

use App\Models\LegacyBalance;

/** Totals carried over from the old records system, counted in the CGPA as if they were results here. */
final readonly class BroughtForward
{
    public function __construct(
        public float $tcp,
        public int $tnu,
        public ?int $unitsPassed,
        public float $cgpa,
        public ?string $asOf,
        public ?string $note,
    ) {
    }

    public static function fromBalance(LegacyBalance $b): self
    {
        return new self((float) $b->tcp, (int) $b->tnu, $b->units_passed === null ? null : (int) $b->units_passed, (float) $b->cgpa, $b->asOf?->name, $b->note);
    }
}
