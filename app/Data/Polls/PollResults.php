<?php

namespace App\Data\Polls;

/** Turnout and the result of every question. */
final readonly class PollResults
{
    /** @param  QuestionResult[]  $questions */
    public function __construct(public int $eligible, public int $voted, public array $questions)
    {
    }

    public function turnout(): float
    {
        return $this->eligible > 0 ? round($this->voted / $this->eligible * 100, 1) : 0.0;
    }
}
