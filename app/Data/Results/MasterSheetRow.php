<?php

namespace App\Data\Results;

use App\Models\CourseResult;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * One student's line on a master sheet: this semester's results with TCR (units registered), TCE (units earned),
 * TGP (grade points) and GPA, the cumulative totals brought forward from earlier semesters, the new cumulative
 * totals and CGPA, outstanding carry-overs (waived ones listed apart) and the remark.
 */
final readonly class MasterSheetRow
{
    /**
     * @param  Collection<int, CourseResult>  $results  keyed by course id
     * @param  Collection<int, string>  $carryovers  course codes
     * @param  Collection<int, string>  $waived  course codes
     */
    public function __construct(
        public Student $student,
        public Collection $results,
        public int $tcr,
        public int $tce,
        public float $tgp,
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
        public string $remark,
        public string $bucket,
    ) {
    }
}
