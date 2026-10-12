<?php

namespace App\Data\Schedule;

use Illuminate\Support\Collection;

/** A student's papers this semester (grouped by unit), their published seats, and papers that clash. */
final readonly class ExamSchedule
{
    public function __construct(public Collection $papers, public Collection $seats, public Collection $clash)
    {
    }
}
