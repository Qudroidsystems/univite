<?php

namespace App\Data\Results;

use App\Models\AcademicSession;
use App\Models\CourseResult;
use App\Models\SemesterType;
use Illuminate\Support\Collection;

/** One semester on a transcript: its published results and GPA. */
final readonly class TranscriptTerm
{
    /** @param  Collection<int, CourseResult>  $results */
    public function __construct(
        public AcademicSession $session,
        public SemesterType $semester,
        public Collection $results,
        public int $units,
        public float $gpa,
    ) {
    }
}
