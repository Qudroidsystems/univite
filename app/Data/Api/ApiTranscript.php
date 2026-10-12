<?php

namespace App\Data\Api;

use App\Data\Results\Transcript;
use App\Data\Results\TranscriptTerm;

/** A student's published results by semester, with GPA, CGPA, class and standing. */
final readonly class ApiTranscript
{
    /** @param  array<int, array<string, mixed>>  $terms */
    public function __construct(
        public float $cgpa, public int $units, public int $earned, public ?string $class, public ?string $standing, public array $terms,
    ) {
    }

    public static function from(Transcript $t): self
    {
        return new self($t->cgpa, $t->units, $t->earned, $t->class, $t->standing, $t->terms->map(fn (TranscriptTerm $term) => [
            'session' => $term->session->name, 'semester' => $term->semester->name, 'gpa' => $term->gpa, 'units' => $term->units,
            'courses' => $term->results->map(fn ($r) => ApiCourseResult::from($r)->toArray())->values()->all(),
        ])->values()->all());
    }

    public function toArray(): array
    {
        return ['cgpa' => $this->cgpa, 'units' => $this->units, 'units_passed' => $this->earned, 'class' => $this->class, 'standing' => $this->standing, 'semesters' => $this->terms];
    }
}
