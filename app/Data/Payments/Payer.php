<?php

namespace App\Data\Payments;

/** The person paying, as the gateway needs them. */
final readonly class Payer
{
    public function __construct(public string $name, public string $email, public ?string $phone = null)
    {
    }
}
