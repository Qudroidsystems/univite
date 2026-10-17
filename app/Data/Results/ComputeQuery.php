<?php

namespace App\Data\Results;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\SemesterType;
use App\Models\StudyCategory;
use Illuminate\Http\Request;

/** What the Head of Department picked on Compute Results: department, student category, course, level, session and semester. */
final readonly class ComputeQuery
{
    public function __construct(
        public ?Department $department,
        public ?StudyCategory $category,
        public ?Course $course,
        public ?int $level,
        public ?AcademicSession $session,
        public ?SemesterType $semesterType,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            $request->filled('department_id') ? Department::find((int) $request->input('department_id')) : null,
            $request->filled('study_category_id') ? StudyCategory::find((int) $request->input('study_category_id')) : null,
            $request->filled('course_id') ? Course::find((int) $request->input('course_id')) : null,
            $request->filled('level') ? (int) $request->input('level') : null,
            $request->filled('session_id') ? AcademicSession::find((int) $request->input('session_id')) : AcademicSession::current(),
            $request->filled('semester_type_id') ? SemesterType::find((int) $request->input('semester_type_id')) : SemesterType::orderBy('sort_order')->first(),
        );
    }

    public function complete(): bool
    {
        return $this->department && $this->level && $this->session && $this->semesterType;
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        return array_filter([
            'department_id' => $this->department?->id, 'study_category_id' => $this->category?->id, 'course_id' => $this->course?->id,
            'level' => $this->level, 'session_id' => $this->session?->id, 'semester_type_id' => $this->semesterType?->id,
        ], fn ($v) => $v !== null);
    }

    /** "Science Education, NCE, 200 level, 2026/2027 First Semester" */
    public function title(): string
    {
        return implode(', ', array_filter([$this->department?->name, $this->category?->name, $this->level ? $this->level . ' level' : null,
            trim(($this->session?->name ?? '') . ' ' . ($this->semesterType?->name ?? ''))]));
    }
}
