<?php

namespace App\Data\Results;

use App\Models\ComputedResult;
use App\Models\CourseResult;
use App\Models\Student;
use Illuminate\Support\Collection;

/** Everything Compute Results shows before and after computing. */
final readonly class ComputeOverview
{
    /**
     * @param  Collection<int, Student>  $students  everyone on the computation (registered or not)
     * @param  Collection<int, ComputeCourse>  $courses
     * @param  Collection<int, CourseResult>  $results  the scores shown in the table (one course, or all)
     * @param  Collection<int, ComputedResult>  $computed  keyed by student id
     */
    public function __construct(
        public ComputeQuery $query,
        public Collection $students,
        public Collection $courses,
        public Collection $results,
        public Collection $computed,
    ) {
    }

    /** @return Collection<int, ComputeCourse> courses whose scores are not submitted yet */
    public function pending(): Collection
    {
        return $this->courses->reject(fn (ComputeCourse $c) => $c->ready())->values();
    }

    public function lastComputed(): ?ComputedResult
    {
        return $this->computed->sortByDesc('computed_at')->first();
    }
}
