<?php

namespace App\Data\Payments;

/** What a gateway says happened to a payment. */
final readonly class GatewayResult
{
    public function __construct(
        public string $status,              // successful | failed | pending
        public ?float $amount = null,       // what the gateway says was paid, in naira
        public ?string $currency = null,
        public ?string $gatewayReference = null,
        public ?string $channel = null,     // card, bank_transfer, ussd ...
        public ?string $message = null,
        public array $payload = [],
    ) {
    }

    public static function pending(?string $message = null, array $payload = []): self
    {
        return new self('pending', message: $message, payload: $payload);
    }

    public static function failed(string $message, array $payload = []): self
    {
        return new self('failed', message: $message, payload: $payload);
    }
}
