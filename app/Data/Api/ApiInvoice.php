<?php

namespace App\Data\Api;

use App\Models\Invoice;

/** An invoice; with $detail, its lines and payments too. */
final readonly class ApiInvoice
{
    public function __construct(public array $data)
    {
    }

    public static function from(Invoice $i, bool $detail = false): self
    {
        $data = [
            'id' => $i->id, 'reference' => $i->reference, 'title' => $i->title, 'kind' => $i->kind, 'session' => $i->session?->name,
            'total' => (float) $i->total, 'paid' => (float) $i->amount_paid, 'outstanding' => (float) $i->outstanding, 'status' => $i->status,
            'due_date' => $i->due_date ? \Illuminate\Support\Carbon::parse($i->due_date)->toDateString() : null,
            'pay_url' => route('my.fees.show', $i),
        ];
        if ($detail) {
            $data['lines'] = $i->lines->map(fn ($l) => ['description' => $l->description, 'amount' => (float) $l->amount])->values()->all();
            $data['payments'] = $i->payments->whereIn('status', ['successful', 'reversed', 'pending'])->map(fn ($p) => ApiPayment::from($p)->toArray())->values()->all();
        }

        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
