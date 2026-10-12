<?php

namespace App\Data\TeachingPractice;

final readonly class ScorePublication
{
    /**
     * @param  string[]  $incomplete  students without enough assessed visits
     * @param  string[]  $notRegistered  students not on the course's result sheet
     * @param  string[]  $problems  messages from the result sheet
     */
    public function __construct(public int $saved, public array $incomplete, public array $notRegistered, public array $problems)
    {
    }
}
