<?php

namespace App\Data\Results;

/** An uploaded score file: how many rows were read, and the rows that were not saved with why. */
final readonly class ScoreImport
{
    /** @param  string[]  $problems */
    public function __construct(public int $saved, public array $problems = [])
    {
    }

    /** The first few problems, with a count of the rest. */
    public function summary(int $show = 8): string
    {
        $more = count($this->problems) - $show;

        return implode(' ', array_slice($this->problems, 0, $show)) . ($more > 0 ? " (and {$more} more)" : '');
    }
}
