<?php

namespace App\Data\Payments;

/** Where to send a payer to pay: a plain link, or a form posted to the gateway (Remita). */
final readonly class GatewayRedirect
{
    /** @param  array<string, string>  $fields  form fields when the method is POST */
    public function __construct(
        public string $url,
        public string $method = 'GET',
        public array $fields = [],
        public ?string $gatewayReference = null,
    ) {
    }
}
