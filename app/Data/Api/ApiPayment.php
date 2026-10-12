<?php

namespace App\Data\Api;

use App\Models\Payment;

/** One payment against an invoice. */
final readonly class ApiPayment
{
    public function __construct(
        public int $id, public string $reference, public float $amount, public string $status, public ?string $gateway, public ?string $paidAt, public ?string $receiptUrl,
    ) {
    }

    public static function from(Payment $p): self
    {
        return new self($p->id, $p->reference, (float) $p->amount, $p->status, $p->gateway, $p->paid_at?->toIso8601String(),
            $p->status === 'successful' ? route('my.receipt', $p) : null);
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'reference' => $this->reference, 'amount' => $this->amount, 'status' => $this->status, 'gateway' => $this->gateway, 'paid_at' => $this->paidAt, 'receipt_url' => $this->receiptUrl];
    }
}
