<?php

namespace App\Data\Senate;

use App\Models\Course;
use App\Models\SenateDecision;
use Illuminate\Support\Collection;

/** A course at the Senate review: its grade spread, pass rate, lecturers, approval trail and the findings Senate should see. */
final readonly class SenateCourse
{
    /**
     * @param  array<string, int>  $grades  letter => count
     * @param  string[]  $lecturers
     * @param  Collection<int, \App\Models\ResultSheetEvent>  $trail
     * @param  SenateReason[]  $reasons
     */
    public function __construct(
        public Course $course,
        public ?string $semester,
        public int $registered,
        public int $withResult,
        public int $passed,
        public int $failed,
        public ?float $average,
        public ?float $departmentAverage,
        public array $grades,
        public array $lecturers,
        public ?string $sheetStatus,
        public ?string $stage,
        public ?string $submittedAt,
        public Collection $trail,
        public array $reasons,
        public ?SenateDecision $decision,
        public ?int $sheetId = null,
    ) {
    }

    public function passRate(): ?float
    {
        return $this->withResult ? round($this->passed / $this->withResult * 100, 1) : null;
    }

    public function status(): string
    {
        return array_filter($this->reasons, fn (SenateReason $r) => ! $r->info) ? 'needs_decision' : 'eligible';
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
}
