<?php

namespace App\Data\Legacy;

/** One checked opening balance (a student's totals from the old system), ready to save. */
final readonly class BalanceRow
{
    public function __construct(
        public int $row,
        public int $studentId,
        public string $matric,
        public string $studentName,
        public int $tnu,
        public float $tcp,
        public ?int $unitsPassed,
        public ?string $sessionName,
        public ?string $note,
    ) {
    }

    public function cgpa(): float
    {
        return round($this->tcp / $this->tnu, 2);
    }

    public function preview(): array
    {
        return ['row' => $this->row, 'matric' => $this->matric, 'student' => $this->studentName, 'tnu' => $this->tnu, 'tcp' => $this->tcp,
            'cgpa' => $this->cgpa(), 'units_passed' => $this->unitsPassed, 'session' => $this->sessionName];
    }
}
