<?php

namespace App\Data\Accommodation;

final readonly class AllocationRun
{
    public function __construct(public int $allocated, public int $unsuccessful, public int $alreadyPlaced)
    {
    }
}
