<?php

namespace App\Data\Api;

use App\Models\CourseResult;

/** One published course result. */
final readonly class ApiCourseResult
{
    public function __construct(
        public int $id, public ?string $code, public ?string $title, public int $units, public ?float $ca, public ?float $exam,
        public ?float $total, public ?string $grade, public ?float $points, public bool $passed,
    ) {
    }

    public static function from(CourseResult $r): self
    {
        return new self($r->id, $r->course?->code, $r->course?->title, (int) $r->credit_units, $r->ca !== null ? (float) $r->ca : null,
            $r->exam !== null ? (float) $r->exam : null, $r->total !== null ? (float) $r->total : null, $r->grade,
            $r->points !== null ? (float) $r->points : null, (bool) $r->is_pass);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'title' => $this->title, 'units' => $this->units, 'ca' => $this->ca, 'exam' => $this->exam,
            'total' => $this->total, 'grade' => $this->grade, 'points' => $this->points, 'passed' => $this->passed,
        ];
    }
}
