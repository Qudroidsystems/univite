<?php

namespace App\Services\Payments;

use App\Models\Payment;
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

    public function initialize(Payment $payment): string
    {
        throw new LogicException('Offline payments are recorded by the bursary; there is no payment page.');
    }

    public function verify(Payment $payment): string
    {
        return $payment->status;
    }
}
