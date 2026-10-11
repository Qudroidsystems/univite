<?php

namespace App\Data\Legacy;

/** One checked course result from the old records, ready to save. $courseId is null for a course added by the import. */
final readonly class ResultRow
{
    public function __construct(
        public int $row,
        public int $studentId,
        public string $matric,
        public string $studentName,
        public ?int $courseId,
        public string $courseKey,
        public string $courseCode,
        public string $sessionName,
        public int $semesterTypeId,
        public string $semesterName,
        public int $units,
        public ?float $ca,
        public ?float $exam,
        public ?float $total,
        public string $grade,
        public float $points,
        public bool $isPass,
    ) {
    }

    public function preview(): array
    {
        return ['row' => $this->row, 'matric' => $this->matric, 'student' => $this->studentName, 'course' => $this->courseCode, 'session' => $this->sessionName,
            'semester' => $this->semesterName, 'units' => $this->units, 'total' => $this->total, 'grade' => $this->grade, 'points' => $this->points];
    }
}
