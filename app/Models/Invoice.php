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

    protected $fillable = ['reference', 'kind', 'payable_type', 'payable_id', 'academic_session_id', 'title', 'total', 'amount_paid', 'status', 'due_date', 'created_by'];

    protected $casts = ['total' => 'decimal:2', 'amount_paid' => 'decimal:2', 'due_date' => 'date'];

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

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    public function getOutstandingAttribute(): float
    {
        return $this->status === 'cancelled' ? 0.0 : round((float) $this->total - (float) $this->amount_paid, 2);
    }
}
