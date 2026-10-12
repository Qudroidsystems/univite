<?php

namespace App\Services\Payments;

use App\Data\Payments\GatewayRedirect;
use App\Data\Payments\GatewayResult;
use App\Data\Payments\Payer;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * What every payment gateway must do. Online gateways create a pending Payment, send the payer to the
 * gateway (initialize) and later confirm the result (verify): when the payer returns, from a webhook, or on the
 * schedule. A webhook is only a hint; the payment is always confirmed by asking the gateway with verify().
 */
interface PaymentGateway
{
    /** Short machine name stored on the payment, e.g. "paystack". */
    public function name(): string;

    /** Name shown to payers and admins. */
    public function label(): string;

    /** True for gateways that take the payer to an external page. Offline gateways return false. */
    public function isOnline(): bool;

    /** True when the keys this gateway needs have been entered under Payment settings. */
    public function isConfigured(): bool;

    /** Start an online payment and say where to send the payer. $returnUrl is where the gateway sends them back. */
    public function initialize(Payment $payment, Payer $payer, string $returnUrl): GatewayRedirect;

    /** Ask the gateway what happened. */
    public function verify(Payment $payment): GatewayResult;

    /**
     * The payments a webhook call is about: our references or the gateway's own. Empty when the call is not genuine
     * or not about a payment.
     *
     * @return array<int, string>
     */
    public function webhookReferences(Request $request): array;
}
