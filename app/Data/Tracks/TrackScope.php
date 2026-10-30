<?php

namespace App\Data\Tracks;

/** Which students a move covers, and from and to which session and semester. */
final readonly class TrackScope
{
    public function __construct(
        public int $fromSessionId,
        public int $fromSemesterTypeId,
        public int $toSessionId,
        public int $toSemesterTypeId,
        public ?int $facultyId = null,
        public ?int $departmentId = null,
        public ?int $studyCategoryId = null,
        public ?int $programmeId = null,
        public ?int $level = null,
        public ?string $matric = null,
        public bool $needResults = false,
    ) {
    }

    public static function fromArray(array $a): self
    {
        $int = fn ($k) => isset($a[$k]) && $a[$k] !== '' ? (int) $a[$k] : null;

        return new self((int) $a['from_session_id'], (int) $a['from_semester_type_id'], (int) $a['to_session_id'], (int) $a['to_semester_type_id'],
            $int('faculty_id'), $int('department_id'), $int('study_category_id'), $int('programme_id'), $int('level'),
            isset($a['matric']) && trim((string) $a['matric']) !== '' ? strtoupper(trim((string) $a['matric'])) : null, ! empty($a['need_results']));
    }

    public function toArray(): array
    {
        return array_filter(['faculty_id' => $this->facultyId, 'department_id' => $this->departmentId, 'study_category_id' => $this->studyCategoryId,
            'programme_id' => $this->programmeId, 'level' => $this->level, 'matric' => $this->matric, 'need_results' => $this->needResults ?: null], fn ($v) => $v !== null);
    }
}
