<?php

namespace App\Data\Health;

/** Counts for the health centre report. Never carries names or clinical notes. */
final readonly class ClinicStats
{
    /**
     * @param  array<string, int>  $byCategory
     * @param  array<string, int>  $byOutcome
     * @param  array<string, int>  $byWeek  week starting (Y-m-d) => visits
     */
    public function __construct(
        public int $visits,
        public int $people,
        public int $students,
        public int $staff,
        public array $byCategory,
        public array $byOutcome,
        public array $byWeek,
        public int $restDays,
        public int $newStudents,
        public int $newStudentsExamined,
        public array $fitness,
    ) {
    }
}
