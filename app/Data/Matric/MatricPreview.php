<?php

namespace App\Data\Matric;

use App\Models\Student;

/** Who would get a matric number, and which, before anything is issued. */
final readonly class MatricPreview
{
    /** @param  array<int, array{0: Student, 1: string}>  $rows */
    public function __construct(public array $rows)
    {
    }

    public function total(): int
    {
        return count($this->rows);
    }
}
