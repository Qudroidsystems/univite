<?php

namespace App\Data\Hr;

use Carbon\CarbonImmutable;

/** When a member of staff is due to retire, and which rule decides it (age or years of service). */
final readonly class Retirement
{
    public function __construct(public CarbonImmutable $date, public string $reason)
    {
    }

    public function monthsLeft(?CarbonImmutable $from = null): int
    {
        $from ??= CarbonImmutable::today();

        return $this->date->lt($from) ? 0 : (int) $from->diffInMonths($this->date);
    }

    public function isPast(?CarbonImmutable $on = null): bool
    {
        return $this->date->lte($on ?? CarbonImmutable::today());
    }
}
