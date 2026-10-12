<?php

namespace App\Data\Schedule;

use Illuminate\Support\Collection;

/** A member of staff's classes and papers this semester, and invigilation duties to come. */
final readonly class TeachingSchedule
{
    public function __construct(public Collection $classes, public Collection $papers, public Collection $duties)
    {
    }
}
