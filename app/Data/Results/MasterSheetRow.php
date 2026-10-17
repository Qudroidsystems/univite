<?php

namespace App\Data\Results;

use App\Models\CourseResult;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * One student's line on a master sheet: this semester's results with TCP (total credit points: grade point x units),
 * TNU (total number of units) and GPA, the cumulative totals brought forward from earlier semesters, the new cumulative
 * totals and CGPA, outstanding carry-overs (waived ones listed apart) and the remark.
 */
final readonly class MasterSheetRow
{
    /**
     * @param  Collection<int, CourseResult>  $results  keyed by course id
     * @param  Collection<int, string>  $carryovers  course codes
     * @param  Collection<int, string>  $waived  course codes
     * @param  Collection<int, string>  $outstanding  codes of registered courses with no score yet this semester
     */
    public function __construct(
        public Student $student,
        public Collection $results,
        public float $tcp,
        public int $tnu,
        public float $gpa,
        public int $previousUnits,
        public float $previousPoints,
        public float $previousCgpa,
        public int $cumulativeUnits,
        public float $cumulativePoints,
        public float $cgpa,
        public Collection $carryovers,
        public Collection $waived,
        public ?string $standing,
        public int $missing,
        public Collection $outstanding,
        public string $remark,
        public string $bucket,
    ) {
    }
}
