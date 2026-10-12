<?php

namespace App\Data\TeachingPractice;

use Carbon\CarbonImmutable;

/** A supervisor's marks for one lesson observed on one visit. */
final readonly class VisitScore
{
    /** @param  array<string, float>  $marks  criterion key => mark */
    public function __construct(public int $visitNo, public CarbonImmutable $visitedOn, public array $marks, public ?string $lessonTopic, public ?string $remarks)
    {
    }

    public static function rules(): array
    {
        return ['visit_no' => ['required', 'integer', 'min:1', 'max:10'], 'visited_on' => ['required', 'date', 'before_or_equal:today'], 'marks' => ['required', 'array'],
            'marks.*' => ['nullable', 'numeric', 'min:0'], 'lesson_topic' => ['nullable', 'string', 'max:255'], 'remarks' => ['nullable', 'string', 'max:2000']];
    }

    public static function fromArray(array $d): self
    {
        return new self((int) $d['visit_no'], CarbonImmutable::parse($d['visited_on']), array_map('floatval', array_filter($d['marks'] ?? [], fn ($v) => $v !== null && $v !== '')),
            ($d['lesson_topic'] ?? null) ?: null, ($d['remarks'] ?? null) ?: null);
    }
}
