<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A student or staff identity card, or a holder's request for one. The snapshot is what is printed on the card. */
class IdCard extends Model
{
    public const STATUSES = ['requested' => 'Requested', 'active' => 'Valid', 'replaced' => 'Replaced', 'cancelled' => 'Cancelled'];
    public const BADGES = ['requested' => 'bg-warning-subtle text-warning', 'active' => 'bg-success-subtle text-success', 'replaced' => 'bg-secondary-subtle text-secondary', 'cancelled' => 'bg-danger-subtle text-danger'];
    public const REASONS = ['first' => 'First card', 'lost' => 'Lost or stolen', 'damaged' => 'Damaged', 'change' => 'Details have changed', 'renewal' => 'Renewal (expired)'];

    protected $fillable = ['user_id', 'kind', 'serial', 'token', 'status', 'reason', 'snapshot', 'request_note', 'requested_at', 'invoice_id', 'issued_at', 'expires_on',
        'issued_by', 'printed_at', 'printed_by', 'collected_at', 'collected_by', 'cancelled_at', 'cancelled_by', 'cancel_reason'];

    protected $casts = ['snapshot' => 'array', 'requested_at' => 'datetime', 'issued_at' => 'datetime', 'expires_on' => 'date', 'printed_at' => 'datetime',
        'collected_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function snap(string $key): ?string
    {
        return $this->snapshot[$key] ?? null;
    }

    public function photoUrl(): ?string
    {
        return ($p = $this->snap('photo')) ? Storage::disk('public')->url($p) : null;
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->endOfDay()->isPast();
    }

    /** Valid, expired, replaced, cancelled or requested, in plain words. */
    public function stateLabel(): string
    {
        return $this->status === 'active' && $this->isExpired() ? 'Expired' : self::STATUSES[$this->status];
    }

    public function stateBadge(): string
    {
        return $this->status === 'active' && $this->isExpired() ? 'bg-danger-subtle text-danger' : self::BADGES[$this->status];
    }
}
