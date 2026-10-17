<?php

namespace App\Data\Results;

use App\Models\Course;
use App\Models\ResultSheet;
use App\Models\User;
use Illuminate\Support\Collection;

/** One course on Compute Results: its score sheet, who teaches it and who submitted it when. */
final readonly class ComputeCourse
{
    /** @param  Collection<int, User>  $lecturers */
    public function __construct(
        public Course $course,
        public ?ResultSheet $sheet,
        public Collection $lecturers,
        public ?User $submittedBy,
        public int $students,
        public int $scored,
        public int $passed,
        public int $failed,
        public ?float $average,
    ) {
    }

    /** Submitted to (or past) the approval chain, so its scores count when computing. */
    public function ready(): bool
    {
        return $this->sheet !== null && in_array($this->sheet->status, ['submitted', 'approved', 'published'], true);
    }

    public function statusLabel(): string
    {
        return match ($this->sheet?->status) {
            null => 'No score sheet yet',
            'draft' => 'Not submitted',
            'returned' => 'Returned to the lecturer',
            'submitted' => 'Submitted' . ($this->sheet->stage ? ', at ' . $this->sheet->stage->name : ''),
            'approved' => 'Approved',
            'published' => 'Published',
            default => ucfirst((string) $this->sheet->status),
        };
    }
}
