<?php

namespace App\Data\Graduation;

/** Where a student stands against the graduation requirements. */
final readonly class GraduationCheck
{
    /** @param  string[]  $problems */
    public function __construct(
        public float $cgpa,
        public int $unitsPassed,
        public ?string $degreeClass,
        public array $problems = [],
    ) {
    }

    public function eligible(): bool
    {
        return $this->problems === [];
    }
}
