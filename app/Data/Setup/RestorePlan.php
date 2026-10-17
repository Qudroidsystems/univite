<?php

namespace App\Data\Setup;

/**
 * What restoring one section of a snapshot changes (or changed): rows or values added back, changed back, removed,
 * and anything left in place with the reason.
 */
final readonly class RestorePlan
{
    /** @param  string[]  $kept */
    public function __construct(public int $added = 0, public int $updated = 0, public int $removed = 0, public array $kept = [])
    {
    }

    public function plus(self $other): self
    {
        return new self($this->added + $other->added, $this->updated + $other->updated, $this->removed + $other->removed, array_merge($this->kept, $other->kept));
    }

    public function changes(): int
    {
        return $this->added + $this->updated + $this->removed;
    }

    public function summary(): string
    {
        if (! $this->changes()) {
            return 'Already the same';
        }

        return implode(', ', array_filter([
            $this->added ? "{$this->added} added back" : null,
            $this->updated ? "{$this->updated} changed back" : null,
            $this->removed ? "{$this->removed} removed" : null,
        ]));
    }
}
