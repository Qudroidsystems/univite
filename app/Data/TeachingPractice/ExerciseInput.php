<?php

namespace App\Data\TeachingPractice;

use App\Data\Campus\Audience;
use Carbon\CarbonImmutable;

final readonly class ExerciseInput
{
    /** @param  array<int, array{key: string, label: string, max: int}>  $criteria */
    public function __construct(
        public string $name,
        public int $sessionId,
        public ?int $semesterTypeId,
        public ?int $courseId,
        public Audience $audience,
        public ?CarbonImmutable $startsOn,
        public ?CarbonImmutable $endsOn,
        public int $visitsRequired,
        public array $criteria,
        public bool $choicesOpen,
        public int $maxChoices,
        public ?string $letterText,
    ) {
    }

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'semester_type_id' => ['nullable', 'exists:semester_types,id', 'required_with:course_id'], 'course_id' => ['nullable', 'exists:courses,id'],
            'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'visits_required' => ['required', 'integer', 'min:1', 'max:10'],
            'criteria' => ['required', 'array', 'min:1', 'max:20'], 'criteria.*.label' => ['nullable', 'string', 'max:100'], 'criteria.*.max' => ['nullable', 'integer', 'min:1', 'max:100'],
            'choices_open' => ['nullable', 'boolean'], 'max_choices' => ['nullable', 'integer', 'min:1', 'max:10'], 'letter_text' => ['nullable', 'string', 'max:5000'],
        ] + Audience::rules();
    }

    public static function fromArray(array $d): self
    {
        $criteria = [];
        foreach ($d['criteria'] ?? [] as $c) {
            $label = trim((string) ($c['label'] ?? ''));
            if ($label === '' || empty($c['max'])) {
                continue;
            }
            $key = \Illuminate\Support\Str::slug($label, '_') ?: 'c' . count($criteria);
            while (collect($criteria)->contains('key', $key)) {
                $key .= '_';
            }
            $criteria[] = ['key' => $key, 'label' => $label, 'max' => (int) $c['max']];
        }

        return new self(
            trim($d['name']), (int) $d['academic_session_id'], ($d['semester_type_id'] ?? null) ? (int) $d['semester_type_id'] : null,
            ($d['course_id'] ?? null) ? (int) $d['course_id'] : null, Audience::fromArray(($d['audience'] ?? []) + ['who' => 'students']),
            ($d['starts_on'] ?? null) ? CarbonImmutable::parse($d['starts_on']) : null, ($d['ends_on'] ?? null) ? CarbonImmutable::parse($d['ends_on']) : null,
            (int) $d['visits_required'], $criteria, (bool) ($d['choices_open'] ?? false), (int) ($d['max_choices'] ?? 3), ($d['letter_text'] ?? null) ?: null,
        );
    }

    public function attributes(): array
    {
        return ['name' => $this->name, 'academic_session_id' => $this->sessionId, 'semester_type_id' => $this->semesterTypeId, 'course_id' => $this->courseId,
            'audience' => $this->audience->toArray(), 'starts_on' => $this->startsOn, 'ends_on' => $this->endsOn, 'visits_required' => $this->visitsRequired,
            'criteria' => $this->criteria, 'choices_open' => $this->choicesOpen, 'max_choices' => $this->maxChoices, 'letter_text' => $this->letterText];
    }
}
