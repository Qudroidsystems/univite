<?php

namespace App\Data\Campus;

/**
 * Who something is for: students, staff or everyone, optionally narrowed to faculties, departments, programmes,
 * levels and (for students) gender. Empty lists mean "no restriction". Stored as JSON on polls, hostels and
 * teaching practice exercises; read through AudienceResolver.
 */
final readonly class Audience
{
    public const WHO = ['students' => 'Students', 'staff' => 'Staff', 'everyone' => 'Students and staff'];

    /**
     * @param  int[]  $faculties
     * @param  int[]  $departments
     * @param  int[]  $programmes
     * @param  int[]  $levels
     * @param  string[]  $genders
     */
    public function __construct(
        public string $who = 'students',
        public array $faculties = [],
        public array $departments = [],
        public array $programmes = [],
        public array $levels = [],
        public array $genders = [],
    ) {
    }

    public static function fromArray(?array $a): self
    {
        $a ??= [];
        $ints = fn ($k) => array_values(array_unique(array_map('intval', array_filter((array) ($a[$k] ?? []), fn ($v) => $v !== null && $v !== ''))));

        return new self(
            in_array($a['who'] ?? null, array_keys(self::WHO), true) ? $a['who'] : 'students',
            $ints('faculties'), $ints('departments'), $ints('programmes'), $ints('levels'),
            array_values(array_filter(array_map('strval', (array) ($a['genders'] ?? [])))),
        );
    }

    public function toArray(): array
    {
        return ['who' => $this->who, 'faculties' => $this->faculties, 'departments' => $this->departments, 'programmes' => $this->programmes, 'levels' => $this->levels, 'genders' => $this->genders];
    }

    public function includesStudents(): bool
    {
        return $this->who !== 'staff';
    }

    public function includesStaff(): bool
    {
        return $this->who !== 'students';
    }

    public function isNarrowed(): bool
    {
        return $this->faculties || $this->departments || $this->programmes || $this->levels || $this->genders;
    }

    /** Validation rules for an "audience" field on a form. */
    public static function rules(string $prefix = 'audience'): array
    {
        return [
            "{$prefix}.who" => ['required', 'in:' . implode(',', array_keys(self::WHO))],
            "{$prefix}.faculties" => ['nullable', 'array'], "{$prefix}.faculties.*" => ['integer', 'exists:faculties,id'],
            "{$prefix}.departments" => ['nullable', 'array'], "{$prefix}.departments.*" => ['integer', 'exists:departments,id'],
            "{$prefix}.programmes" => ['nullable', 'array'], "{$prefix}.programmes.*" => ['integer', 'exists:programmes,id'],
            "{$prefix}.levels" => ['nullable', 'array'], "{$prefix}.levels.*" => ['integer', 'min:1', 'max:1000'],
            "{$prefix}.genders" => ['nullable', 'array'], "{$prefix}.genders.*" => ['string', 'max:20'],
        ];
    }
}
