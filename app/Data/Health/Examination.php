<?php

namespace App\Data\Health;

use App\Models\HealthRecord;
use App\Support\Lookups;
use Illuminate\Validation\Rule;

/** The result of a medical examination (for example the one new students have before clearance). */
final readonly class Examination
{
    public function __construct(
        public string $fitness,
        public ?string $note,
        public ?float $heightCm,
        public ?float $weightKg,
        public ?string $bloodGroup,
        public ?string $genotype,
    ) {
    }

    public static function rules(): array
    {
        return [
            'fitness' => ['required', Rule::in(array_keys(HealthRecord::FITNESS))], 'fitness_note' => ['nullable', 'string', 'max:2000', 'required_unless:fitness,fit'],
            'height_cm' => ['nullable', 'numeric', 'min:30', 'max:260'], 'weight_kg' => ['nullable', 'numeric', 'min:2', 'max:400'],
            'blood_group' => ['nullable', Rule::in(array_keys(Lookups::options('blood_group')))], 'genotype' => ['nullable', Rule::in(array_keys(Lookups::options('genotype')))],
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self($d['fitness'], ($d['fitness_note'] ?? null) ?: null, isset($d['height_cm']) ? (float) $d['height_cm'] : null,
            isset($d['weight_kg']) ? (float) $d['weight_kg'] : null, ($d['blood_group'] ?? null) ?: null, ($d['genotype'] ?? null) ?: null);
    }
}
