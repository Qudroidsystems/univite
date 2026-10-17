<?php

namespace App\Data\Results;

/** What one Compute did. */
final readonly class ComputeOutcome
{
    public function __construct(public int $students, public int $regraded, public int $skippedPublished, public int $pendingCourses)
    {
    }

    public function message(): string
    {
        $m = "Results computed for {$this->students} " . ($this->students === 1 ? 'student' : 'students') . '.';
        if ($this->regraded) {
            $m .= " {$this->regraded} " . ($this->regraded === 1 ? 'score was' : 'scores were') . ' regraded by the key to grade.';
        }
        if ($this->skippedPublished) {
            $m .= " {$this->skippedPublished} published " . ($this->skippedPublished === 1 ? 'score keeps its' : 'scores keep their') . ' grade.';
        }
        if ($this->pendingCourses) {
            $m .= " {$this->pendingCourses} " . ($this->pendingCourses === 1 ? 'course has' : 'courses have') . ' no submitted scores yet, so those students show INC until you compute again.';
        }

        return $m;
    }
}
