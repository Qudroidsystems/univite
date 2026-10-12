<?php

/*
| Payment gateways the portal can use. "manual" (offline payments recorded by the bursary) is always
| available. The online gateways are switched on and given their keys under Payment settings in the portal
| (keys are stored encrypted). Another gateway is added by writing a class that implements
| App\Services\Payments\PaymentGateway and listing it here.
*/
return [
    'currency' => 'NGN',
    'symbol'   => '₦',

    'gateways' => [
        'manual'      => \App\Services\Payments\ManualGateway::class,
        'paystack'    => \App\Services\Payments\PaystackGateway::class,
        'flutterwave' => \App\Services\Payments\FlutterwaveGateway::class,
        'remita'      => \App\Services\Payments\RemitaGateway::class,
    ],
];
