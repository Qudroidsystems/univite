<?php

namespace App\Data\Schedule;

use App\Models\Semester;

/** The semester running now, as the session and semester type timetables are filed under. */
final readonly class CurrentTerm
{
    public function __construct(public ?int $sessionId, public ?int $semesterTypeId, public ?Semester $semester)
    {
    }
}
