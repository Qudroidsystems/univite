<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * What every payment gateway must do. Online gateways create a pending Payment, send the payer to the
 * gateway (initialize) and later confirm the result (verify), either when the payer returns or from a webhook.
 */
interface PaymentGateway
{
    /** Short machine name stored on the payment, e.g. "paystack". */
    public function name(): string;

    /** Name shown to payers and admins. */
    public function label(): string;

    /** True for gateways that take the payer to an external page. Offline gateways return false. */
    public function isOnline(): bool;

    /** Start an online payment. Returns the URL to send the payer to. */
    public function initialize(Payment $payment): string;

    /** Ask the gateway what happened. Returns the final status: successful | failed | pending. */
    public function verify(Payment $payment): string;
}
