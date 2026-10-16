<?php

namespace App\Data\Results;

use App\Models\Course;
use App\Models\ResultSheet;

/** One course column on a master sheet: where its score sheet is in the approval chain and how the class did. */
final readonly class MasterSheetCourse
{
    public function __construct(
        public Course $course,
        public ?ResultSheet $sheet,
        public int $registered,
        public int $passed,
        public int $failed,
        public ?float $average,
    ) {
    }

    /** "Published", "Approved, not published", "At Senate", "Being marked" ... */
    public function statusLabel(): string
    {
        return match ($this->sheet?->status) {
            'published' => 'Published',
            'approved' => 'Approved, not published',
            'submitted' => 'At ' . ($this->sheet->stage?->name ?? 'approval'),
            'draft' => 'Being marked',
            default => 'Not started',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this->sheet?->status, ['approved', 'published'], true);
    }
}
