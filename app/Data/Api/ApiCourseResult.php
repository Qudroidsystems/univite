<?php

namespace App\Data\Api;

use App\Models\CourseResult;

/** One published course result, with the mark for each assessment when the course was marked on an assessment scheme. */
final readonly class ApiCourseResult
{
    /** @param  array<int, array{code: string, name: string, component: string, max: float, score: ?float}>  $assessments */
    public function __construct(
        public int $id, public ?string $code, public ?string $title, public int $units, public ?float $ca, public ?float $exam,
        public ?float $total, public ?string $grade, public ?float $points, public bool $passed, public array $assessments = [],
    ) {
    }

    public static function from(CourseResult $r): self
    {
        $assessments = $r->relationLoaded('assessmentScores')
            ? $r->assessmentScores->filter(fn ($m) => $m->item)->sortBy(fn ($m) => [$m->item->sort_order, $m->item->id])->map(fn ($m) => [
                'code' => $m->item->code, 'name' => $m->item->name, 'component' => $m->item->component, 'max' => (float) $m->item->max_score, 'score' => (float) $m->score,
            ])->values()->all()
            : [];

        return new self($r->id, $r->course?->code, $r->course?->title, (int) $r->credit_units, $r->ca !== null ? (float) $r->ca : null,
            $r->exam !== null ? (float) $r->exam : null, $r->total !== null ? (float) $r->total : null, $r->grade,
            $r->points !== null ? (float) $r->points : null, (bool) $r->is_pass, $assessments);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'title' => $this->title, 'units' => $this->units, 'ca' => $this->ca, 'exam' => $this->exam,
            'total' => $this->total, 'grade' => $this->grade, 'points' => $this->points, 'passed' => $this->passed, 'assessments' => $this->assessments,
        ];
    }
}
