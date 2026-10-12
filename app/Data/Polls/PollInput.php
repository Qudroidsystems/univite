<?php

namespace App\Data\Polls;

use App\Data\Campus\Audience;
use Carbon\CarbonImmutable;

/** The details of a poll or election as typed on its form. */
final readonly class PollInput
{
    public function __construct(
        public string $title,
        public ?string $description,
        public string $kind,
        public Audience $audience,
        public CarbonImmutable $opensAt,
        public CarbonImmutable $closesAt,
        public bool $isSecret,
        public string $resultsShown,
    ) {
    }

    public static function fromArray(array $d): self
    {
        return new self(
            trim($d['title']), $d['description'] ?? null, $d['kind'] ?? 'poll', Audience::fromArray($d['audience'] ?? []),
            CarbonImmutable::parse($d['opens_at']), CarbonImmutable::parse($d['closes_at']),
            (bool) ($d['is_secret'] ?? false), $d['results_shown'] ?? 'after_close',
        );
    }

    public function attributes(): array
    {
        return ['title' => $this->title, 'description' => $this->description, 'kind' => $this->kind, 'audience' => $this->audience->toArray(),
            'opens_at' => $this->opensAt, 'closes_at' => $this->closesAt, 'is_secret' => $this->isSecret, 'results_shown' => $this->resultsShown];
    }
}
