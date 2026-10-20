<?php

namespace App\Data\Senate;

use App\Models\SenateDecision;
use App\Models\Student;

/** A student at the Senate review: the figures Senate reads and the findings behind their status. */
final readonly class SenateStudent
{
    public const STATUSES = ['eligible' => 'Eligible', 'not_eligible' => 'Not eligible', 'needs_decision' => 'Needs Senate decision'];

    public const BADGES = ['eligible' => 'bg-success-subtle text-success', 'not_eligible' => 'bg-danger-subtle text-danger', 'needs_decision' => 'bg-warning-subtle text-warning'];

    /** @param  SenateReason[]  $reasons */
    public function __construct(
        public Student $student,
        public int $level,
        public bool $final,
        public float $cgpa,
        public float $gpa,
        public ?string $class,
        public int $unitsPassed,
        public ?int $unitsNeeded,
        public int $unitsThisSession,
        public array $reasons,
        public ?SenateDecision $decision,
        public ?float $previousGpa = null,
    ) {
    }

    public function status(): string
    {
        $active = array_filter($this->reasons, fn (SenateReason $r) => ! $r->info);
        if (array_filter($active, fn (SenateReason $r) => $r->blocks)) {
            return 'not_eligible';
        }

        return $active ? 'needs_decision' : 'eligible';
    }

    public function has(string $code): bool
    {
        foreach ($this->reasons as $r) {
            if ($r->code === $code) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, SenateReason[]> group => reasons */
    public function grouped(): array
    {
        $out = [];
        foreach ($this->reasons as $r) {
            $out[$r->group()][] = $r;
        }
        ksort($out);

        return $out;
    }

    public function summaryText(): string
    {
        return implode(' ', array_map(fn (SenateReason $r) => $r->text, array_filter($this->reasons, fn (SenateReason $r) => ! $r->info)));
    }
}
