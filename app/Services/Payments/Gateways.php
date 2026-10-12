<?php

namespace App\Services\Payments;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/** The payment gateways listed in config/payments.php, and which of them payers can use right now. */
class Gateways
{
    public function __construct(private Container $container, private PaymentSettings $settings)
    {
    }

    public function get(string $name): PaymentGateway
    {
        $class = config("payments.gateways.$name") ?? throw new InvalidArgumentException("Unknown payment gateway [$name].");

        return $this->container->make($class);
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array
    {
        return collect(config('payments.gateways'))->map(fn ($class) => $this->container->make($class))->all();
    }

    /** Online gateways switched on under Payment settings with their keys entered: the ones a payer may choose. */
    public function enabled(): array
    {
        return array_filter($this->all(), fn (PaymentGateway $g) => $g->isOnline() && $this->settings->on($g->name() . '_enabled') && $g->isConfigured());
    }
}
