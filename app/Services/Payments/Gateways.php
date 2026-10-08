<?php

namespace App\Services\Payments;

use InvalidArgumentException;

class Gateways
{
    public static function get(string $name): PaymentGateway
    {
        $class = config("payments.gateways.$name") ?? throw new InvalidArgumentException("Unknown payment gateway [$name].");

        return app($class);
    }

    /** @return array<string, PaymentGateway> */
    public static function all(): array
    {
        return collect(config('payments.gateways'))->map(fn ($class) => app($class))->all();
    }

    /** Gateways a payer can use to pay online themselves. */
    public static function online(): array
    {
        return array_filter(self::all(), fn (PaymentGateway $g) => $g->isOnline());
    }
}
