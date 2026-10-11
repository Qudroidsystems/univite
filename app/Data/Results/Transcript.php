<?php

namespace App\Data\Results;

use Illuminate\Support\Collection;

/** A student's published results by semester, with the running totals the CGPA rules produce. */
final readonly class Transcript
{
    /** @param  Collection<int, TranscriptTerm>  $terms */
    public function __construct(
        public Collection $terms,
        public float $cgpa,
        public int $units,
        public float $tcp,
        public int $earned,
        public ?string $class,
        public ?string $standing,
        public string $method,
        public ?BroughtForward $broughtForward = null,
    ) {
    }

    /** Anything to show: published results or totals brought forward. */
    public function hasRecords(): bool
    {
        return $this->terms->isNotEmpty() || $this->broughtForward !== null;
    }
}
