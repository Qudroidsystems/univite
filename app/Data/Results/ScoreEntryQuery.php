<?php

namespace App\Data\Results;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\SemesterType;
use App\Models\StudyCategory;
use Illuminate\Http\Request;

/**
 * What Enter Scores shows. "student" (post by student) lists only the students of the chosen department, student
 * category and level who take the course; "subject" (post by subject) lists everyone taking the course, and the
 * department, category and level only narrow the list of courses. Session and semester default to the running one.
 */
final readonly class ScoreEntryQuery
{
    public const MODES = ['student' => 'Post by student', 'subject' => 'Post by subject'];

    public function __construct(
        public string $mode,
        public ?AcademicSession $session,
        public ?SemesterType $semesterType,
        public ?Department $department,
        public ?StudyCategory $category,
        public ?int $level,
        public ?Course $course,
    ) {
    }

    public static function fromRequest(Request $request, ?AcademicSession $currentSession = null, ?SemesterType $currentType = null): self
    {
        $find = fn (string $key, string $model) => $request->filled($key) ? $model::find((int) $request->input($key)) : null;

        return new self(
            array_key_exists((string) $request->input('mode'), self::MODES) ? (string) $request->input('mode') : 'subject',
            $find('session_id', AcademicSession::class) ?? $currentSession ?? AcademicSession::current(),
            $find('semester_type_id', SemesterType::class) ?? $currentType ?? SemesterType::orderBy('sort_order')->first(),
            $find('department_id', Department::class),
            $find('study_category_id', StudyCategory::class),
            $request->filled('level') ? (int) $request->input('level') : null,
            $find('course_id', Course::class),
        );
    }

    public function byStudent(): bool
    {
        return $this->mode === 'student';
    }

    /** Post by student needs the class (department, category and level) as well as the course. */
    public function missing(): array
    {
        $need = ['session' => $this->session, 'semester' => $this->semesterType, 'course' => $this->course];
        if ($this->byStudent()) {
            $need += ['department' => $this->department, 'student category' => $this->category, 'level' => $this->level];
        }

        return array_keys(array_filter($need, fn ($v) => $v === null));
    }

    /** @return array<string, int|string> the query string for links back to this view */
    public function params(array $override = []): array
    {
        return array_filter(array_merge([
            'mode' => $this->mode,
            'session_id' => $this->session?->id,
            'semester_type_id' => $this->semesterType?->id,
            'department_id' => $this->department?->id,
            'study_category_id' => $this->category?->id,
            'level' => $this->level,
            'course_id' => $this->course?->id,
        ], $override), fn ($v) => $v !== null && $v !== '');
    }
}
