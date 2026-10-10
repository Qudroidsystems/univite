<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const CHANNELS = [
        'bank_deposit' => 'Bank deposit (teller)', 'bank_transfer' => 'Bank transfer', 'pos' => 'POS',
        'cash' => 'Cash at the bursary', 'other' => 'Other',
    ];

    protected $fillable = ['invoice_id', 'reference', 'gateway', 'gateway_reference', 'amount', 'status', 'channel', 'paid_at', 'recorded_by', 'note', 'payload', 'reverses_payment_id', 'reversed_at', 'reversed_by', 'reversal_reason'];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'payload' => 'array', 'reversed_at' => 'datetime'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The reversal entry for a reversed payment, or the payment a reversal entry reverses. */
    public function reversal(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'reverses_payment_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
