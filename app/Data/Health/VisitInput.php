<?php

namespace App\Data\Health;

use App\Models\ClinicVisit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/** One attendance at the health centre, as the clinician records it. */
final readonly class VisitInput
{
    public function __construct(
        public CarbonImmutable $seenAt,
        public string $category,
        public ?string $complaint,
        public ?string $findings,
        public ?string $diagnosis,
        public ?string $treatment,
        public string $outcome,
        public ?string $referredTo,
        public int $restDays,
    ) {
    }

    public static function rules(): array
    {
        return [
            'seen_at' => ['nullable', 'date', 'before_or_equal:now'], 'category' => ['required', Rule::in(array_keys(ClinicVisit::CATEGORIES))],
            'complaint' => ['nullable', 'string', 'max:5000'], 'findings' => ['nullable', 'string', 'max:5000'], 'diagnosis' => ['nullable', 'string', 'max:2000'],
            'treatment' => ['nullable', 'string', 'max:5000'], 'outcome' => ['required', Rule::in(array_keys(ClinicVisit::OUTCOMES))],
            'referred_to' => ['nullable', 'string', 'max:255', 'required_if:outcome,referred'], 'rest_days' => ['nullable', 'integer', 'min:0', 'max:60'],
        ];
    }

    public static function fromArray(array $d): self
    {
        $s = fn ($k) => isset($d[$k]) && trim((string) $d[$k]) !== '' ? trim((string) $d[$k]) : null;

        return new self(isset($d['seen_at']) && $d['seen_at'] ? CarbonImmutable::parse($d['seen_at']) : CarbonImmutable::now(), $d['category'],
            $s('complaint'), $s('findings'), $s('diagnosis'), $s('treatment'), $d['outcome'], $s('referred_to'), (int) ($d['rest_days'] ?? 0));
    }
}
