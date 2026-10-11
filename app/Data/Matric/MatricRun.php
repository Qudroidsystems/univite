<?php

namespace App\Data\Matric;

/** What a generate run did. When approval is on, the numbers wait in the batch instead of being published. */
final readonly class MatricRun
{
    public function __construct(public string $batch, public int $issued, public bool $pendingApproval)
    {
    }
}
