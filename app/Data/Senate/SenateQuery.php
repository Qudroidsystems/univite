<?php

namespace App\Data\Senate;

use App\Models\AcademicSession;
use App\Models\SemesterType;
use Illuminate\Http\Request;

/** What the Senate review is looking at: the session (and semester), where, and which rows. */
final readonly class SenateQuery
{
    public function __construct(
        public ?AcademicSession $session,
        public ?SemesterType $semester = null,
        public ?int $facultyId = null,
        public ?int $departmentId = null,
        public ?int $programmeId = null,
        public ?int $level = null,
        public ?string $status = null,      // eligible | not_eligible | needs_decision
        public ?string $flag = null,        // a reason code
        public ?string $decision = null,    // none | approved | deferred | referred
        public ?string $search = null,
        public ?string $course = null,      // course code (courses view)
        public bool $finalOnly = false,     // only the graduating level
    ) {
    }

    public static function fromRequest(Request $r): self
    {
        $session = $r->filled('session_id') ? AcademicSession::find($r->input('session_id')) : (AcademicSession::current() ?? AcademicSession::orderByDesc('name')->first());

        return new self(
            $session,
            $r->filled('semester_type_id') ? SemesterType::find($r->input('semester_type_id')) : null,
            $r->filled('faculty_id') ? (int) $r->input('faculty_id') : null,
            $r->filled('department_id') ? (int) $r->input('department_id') : null,
            $r->filled('programme_id') ? (int) $r->input('programme_id') : null,
            $r->filled('level') ? (int) $r->input('level') : null,
            $r->input('status') ?: null,
            $r->input('flag') ?: null,
            $r->input('decision') ?: null,
            trim((string) $r->input('q')) ?: null,
            trim((string) $r->input('course')) ?: null,
            $r->boolean('final_only'),
        );
    }

    /** The filters as query-string values (for links and exports). */
    public function params(): array
    {
        return array_filter([
            'session_id' => $this->session?->id, 'semester_type_id' => $this->semester?->id, 'faculty_id' => $this->facultyId, 'department_id' => $this->departmentId,
            'programme_id' => $this->programmeId, 'level' => $this->level, 'status' => $this->status, 'flag' => $this->flag, 'decision' => $this->decision,
            'q' => $this->search, 'course' => $this->course, 'final_only' => $this->finalOnly ? 1 : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function title(): string
    {
        return trim(($this->session?->name ?? '') . ' ' . ($this->semester?->name ?? '(whole session)'));
    }
}
