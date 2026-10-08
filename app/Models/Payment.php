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

    protected $fillable = ['invoice_id', 'reference', 'gateway', 'gateway_reference', 'amount', 'status', 'channel', 'paid_at', 'recorded_by', 'note', 'payload'];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'payload' => 'array'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
