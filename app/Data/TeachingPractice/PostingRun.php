<?php

namespace App\Data\TeachingPractice;

final readonly class PostingRun
{
    public function __construct(public int $posted, public int $unplaced)
    {
    }
}
