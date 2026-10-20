<?php

namespace App\Data\Graduation;

/** Where a student stands against the graduation requirements. */
final readonly class GraduationCheck
{
    /**
     * @param  string[]  $problems
     * @param  string[]  $codes  one per problem: no_results | missing | outstanding | compulsory | units | electives | cgpa_min | max_years
     */
    public function __construct(
        public float $cgpa,
        public int $unitsPassed,
        public ?string $degreeClass,
        public array $problems = [],
        public array $codes = [],
    ) {
    }

    public function eligible(): bool
    {
        return $this->problems === [];
    }
}
