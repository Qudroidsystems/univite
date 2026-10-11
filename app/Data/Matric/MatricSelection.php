<?php

namespace App\Data\Matric;

/** Which waiting students to number: the ticked ones, or everyone waiting (optionally in one programme). */
final readonly class MatricSelection
{
    /** @param  int[]|null  $studentIds */
    public function __construct(public ?array $studentIds = null, public ?int $programmeId = null)
    {
    }

    public static function fromArray(array $a): self
    {
        $ids = ! empty($a['student_ids']) ? array_map('intval', $a['student_ids']) : null;

        return new self($ids, ! empty($a['programme_id']) ? (int) $a['programme_id'] : null);
    }

    public function ticked(): bool
    {
        return $this->studentIds !== null;
    }
}
