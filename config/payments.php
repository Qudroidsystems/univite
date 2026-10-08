<?php

/*
| Payment gateways the portal can use. "manual" (offline payments recorded by the bursary) is always
| available. A live gateway (Paystack, Flutterwave, Remita, eTranzact ...) is added by writing a class that
| implements App\Services\Payments\PaymentGateway and listing it here, with its keys in .env.
*/
return [
    'currency' => 'NGN',
    'symbol'   => '₦',

    'gateways' => [
        'manual' => \App\Services\Payments\ManualGateway::class,
    ],
];
