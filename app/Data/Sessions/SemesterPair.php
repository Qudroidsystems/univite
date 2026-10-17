<?php

namespace App\Data\Sessions;

use App\Models\SemesterType;

/** Copy one semester into another: what is set for $from in the source session goes to $to in the destination. */
final readonly class SemesterPair
{
    public function __construct(public SemesterType $from, public SemesterType $to)
    {
    }

    public function label(): string
    {
        return $this->from->name . ' → ' . $this->to->name;
    }
}
