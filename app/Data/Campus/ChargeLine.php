<?php

namespace App\Data\Campus;

/** One line of a one-off charge to a student (a hostel fee, a card replacement, a library fine). */
final readonly class ChargeLine
{
    public function __construct(public string $description, public float $amount, public ?int $billItemId = null)
    {
    }
}
