<?php

namespace App\Data\Accommodation;

use App\Data\Campus\Audience;
use App\Models\Hostel;
use Illuminate\Validation\Rule;

final readonly class HostelInput
{
    public function __construct(
        public string $name,
        public ?string $code,
        public string $gender,
        public Audience $audience,
        public ?string $description,
        public ?int $wardenId,
        public bool $isActive,
        public ?float $rate,
    ) {
    }

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:20'], 'gender' => ['required', Rule::in(array_keys(Hostel::GENDERS))],
            'description' => ['nullable', 'string', 'max:2000'], 'warden' => ['nullable', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ] + Audience::rules();
    }

    public static function fromArray(array $d, ?int $wardenId): self
    {
        return new self(trim($d['name']), ($d['code'] ?? null) ?: null, $d['gender'], Audience::fromArray(($d['audience'] ?? []) + ['who' => 'students']),
            ($d['description'] ?? null) ?: null, $wardenId, (bool) ($d['is_active'] ?? true), isset($d['rate']) && $d['rate'] !== '' ? (float) $d['rate'] : null);
    }

    public function attributes(): array
    {
        return ['name' => $this->name, 'code' => $this->code, 'gender' => $this->gender, 'audience' => $this->audience->toArray(),
            'description' => $this->description, 'warden_id' => $this->wardenId, 'is_active' => $this->isActive];
    }
}
