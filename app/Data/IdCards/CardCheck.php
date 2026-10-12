<?php

namespace App\Data\IdCards;

use App\Models\IdCard;

/** The answer to scanning a card: is it genuine and valid today, and if not, why. */
final readonly class CardCheck
{
    public function __construct(public ?IdCard $card, public bool $valid, public string $message)
    {
    }
}
