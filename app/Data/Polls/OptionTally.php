<?php

namespace App\Data\Polls;

/** Votes for one answer or candidate. */
final readonly class OptionTally
{
    public function __construct(public int $optionId, public string $label, public int $votes, public float $percent, public bool $leading, public ?string $photoUrl = null)
    {
    }
}
