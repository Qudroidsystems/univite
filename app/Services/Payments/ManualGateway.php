<?php

namespace App\Services\Payments;

use App\Data\Payments\GatewayRedirect;
use App\Data\Payments\GatewayResult;
use App\Data\Payments\Payer;
use App\Models\Payment;
use Illuminate\Http\Request;
use LogicException;

/** Offline payments (bank teller, transfer, POS, cash) that the bursary records by hand. */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Offline (recorded by the bursary)';
    }

    public function isOnline(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function initialize(Payment $payment, Payer $payer, string $returnUrl): GatewayRedirect
    {
        throw new LogicException('Offline payments are recorded by the bursary; there is no payment page.');
    }

    public function verify(Payment $payment): GatewayResult
    {
        return new GatewayResult($payment->status);
    }

    public function webhookReferences(Request $request): array
    {
        return [];
    }
}
