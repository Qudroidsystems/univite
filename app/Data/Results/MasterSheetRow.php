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
    /** Shown in a course cell with no score: the student did not register the course, or registered it and the result is not in yet. */
    public const NOT_REGISTERED = 'NR';
    public const AWAITING_RESULT = 'AR';

    public const MARKS = [self::NOT_REGISTERED => 'Not registered', self::AWAITING_RESULT => 'Awaiting result'];

    /**
     * @param  Collection<int, CourseResult>  $results  keyed by course id
     * @param  Collection<int, string>  $carryovers  course codes
     * @param  Collection<int, string>  $waived  course codes
     * @param  Collection<int, string>  $outstanding  codes of registered courses with no score yet this semester
     * @param  Collection<int, int>  $registered  ids of the courses the student registered this semester
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
        public Collection $registered,
    ) {
    }

    /** NR or AR for a course with no result this semester; null when there is a result. */
    public function mark(int $courseId): ?string
    {
        if ($this->results->has($courseId)) {
            return null;
        }

        return $this->registered->contains($courseId) ? self::AWAITING_RESULT : self::NOT_REGISTERED;
    }
}
