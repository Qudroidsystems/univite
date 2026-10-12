<?php

namespace App\Data\IdCards;

/** What happened when cards were issued for a group: how many, and who was skipped and why. */
final readonly class IssueResult
{
    /**
     * @param  int[]  $cardIds
     * @param  array<int, string>  $skipped  "Name (number): reason"
     */
    public function __construct(public array $cardIds, public array $skipped, public int $alreadyHad)
    {
    }

    public function issued(): int
    {
        return count($this->cardIds);
    }
}
