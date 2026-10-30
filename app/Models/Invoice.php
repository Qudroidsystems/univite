<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Invoice extends Model
{
    public const STATUSES = ['unpaid' => 'Unpaid', 'partial' => 'Part paid', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];
    public const BADGES = [
        'unpaid' => 'bg-danger-subtle text-danger', 'partial' => 'bg-warning-subtle text-warning',
        'paid' => 'bg-success-subtle text-success', 'cancelled' => 'bg-secondary-subtle text-secondary',
    ];

    protected $fillable = ['reference', 'kind', 'payable_type', 'payable_id', 'academic_session_id', 'title', 'total', 'amount_paid', 'status', 'due_date', 'created_by', 'semester_type_id', 'installment_plan', 'opens_at', 'closes_at', 'bill_item_id', 'max_payments'];

    protected $casts = ['total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'due_date' => 'date', 'installment_plan' => 'array', 'opens_at' => 'datetime', 'closes_at' => 'datetime', 'max_payments' => 'integer'];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** The fee this invoice is for, when the fee is paid on its own. */
    public function billItem(): BelongsTo
    {
        return $this->belongsTo(BillItem::class);
    }

    /** How many more payments may be made, or null when there is no limit. */
    public function paymentsLeft(): ?int
    {
        if (! $this->max_payments) {
            return null;
        }

        return max(0, $this->max_payments - $this->payments()->where('status', 'successful')->where('amount', '>', 0)->count());
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    /** open | not_yet | closed: whether payers may pay online right now. Staff can still record offline payments. */
    public function windowState(): string
    {
        if ($this->opens_at && now()->lt($this->opens_at)) {
            return 'not_yet';
        }

        return $this->closes_at && now()->gt($this->closes_at) ? 'closed' : 'open';
    }

    /**
     * The least a payer must pay now. With instalments that is what is left of the instalment they are on
     * (they may always pay more, up to the whole balance). A fee allowing up to N payments splits what is left
     * over the payments left; otherwise the whole balance.
     */
    public function minimumPayment(): float
    {
        // The last payment allowed must clear the balance.
        $left = $this->paymentsLeft();
        if ($left !== null && $left <= 1) {
            return $this->outstanding;
        }

        $plan = $this->installment_plan ?: [];
        if (count($plan) < 2) {
            // Up to N payments with no fixed instalments: any amount that still lets the balance be cleared in time.
            return $left !== null ? round(min($this->outstanding, ceil($this->outstanding / $left * 100) / 100), 2) : $this->outstanding;
        }

        $due = 0.0;
        foreach ($plan as $part) {
            $due += (float) $part;
            if ($due - (float) $this->amount_paid > 0.001) {
                return round(min($this->outstanding, $due - (float) $this->amount_paid), 2);
            }
        }

        return $this->outstanding;
    }

    public function getOutstandingAttribute(): float
    {
        return $this->status === 'cancelled' ? 0.0 : round((float) $this->total - (float) $this->amount_paid, 2);
    }
}
