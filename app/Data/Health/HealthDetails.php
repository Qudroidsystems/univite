<?php

namespace App\Data\Health;

use App\Support\Lookups;
use Illuminate\Validation\Rule;

/** What a person (or the clinic) records about their health: blood group, genotype, allergies, conditions and who to call. */
final readonly class HealthDetails
{
    public function __construct(
        public ?string $bloodGroup,
        public ?string $genotype,
        public ?string $allergies,
        public ?string $conditions,
        public ?string $medications,
        public ?string $disabilities,
        public ?string $emergencyName,
        public ?string $emergencyPhone,
        public ?string $emergencyRelationship,
    ) {
    }

    public static function rules(): array
    {
        return [
            'blood_group' => ['nullable', Rule::in(array_keys(Lookups::options('blood_group')))], 'genotype' => ['nullable', Rule::in(array_keys(Lookups::options('genotype')))],
            'allergies' => ['nullable', 'string', 'max:2000'], 'conditions' => ['nullable', 'string', 'max:2000'], 'medications' => ['nullable', 'string', 'max:2000'],
            'disabilities' => ['nullable', 'string', 'max:2000'], 'emergency_name' => ['nullable', 'string', 'max:255'], 'emergency_phone' => ['nullable', 'string', 'max:30'],
            'emergency_relationship' => ['nullable', 'string', 'max:50'],
        ];
    }

    public static function fromArray(array $d): self
    {
        $s = fn ($k) => isset($d[$k]) && trim((string) $d[$k]) !== '' ? trim((string) $d[$k]) : null;

        return new self($s('blood_group'), $s('genotype'), $s('allergies'), $s('conditions'), $s('medications'), $s('disabilities'),
            $s('emergency_name'), $s('emergency_phone'), $s('emergency_relationship'));
    }

    public function attributes(): array
    {
        return ['blood_group' => $this->bloodGroup, 'genotype' => $this->genotype, 'allergies' => $this->allergies, 'conditions' => $this->conditions,
            'medications' => $this->medications, 'disabilities' => $this->disabilities, 'emergency_name' => $this->emergencyName,
            'emergency_phone' => $this->emergencyPhone, 'emergency_relationship' => $this->emergencyRelationship];
    }
}
