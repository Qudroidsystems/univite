<?php

namespace App\Data\Library;

use App\Models\LibraryHold;
use App\Models\LibraryLoan;

/** What happened when a copy came back: any fine charged and whether it is now kept for someone waiting. */
final readonly class ReturnResult
{
    public function __construct(public LibraryLoan $loan, public float $fine, public int $daysLate, public ?LibraryHold $heldFor)
    {
    }
}
