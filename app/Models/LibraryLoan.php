<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryLoan extends Model
{
    public const FINE_STATUSES = ['none' => 'No fine', 'owed' => 'Fine owed', 'invoiced' => 'Fine billed', 'paid' => 'Fine paid', 'waived' => 'Fine waived'];

    public const FINE_BADGES = ['none' => 'bg-light text-body', 'owed' => 'bg-danger-subtle text-danger', 'invoiced' => 'bg-warning-subtle text-warning',
        'paid' => 'bg-success-subtle text-success', 'waived' => 'bg-secondary-subtle text-secondary'];

    protected $fillable = ['library_copy_id', 'user_id', 'issued_at', 'due_at', 'returned_at', 'renewals', 'issued_by', 'returned_to', 'lost', 'fine', 'fine_status',
        'invoice_id', 'fine_settled_at', 'fine_settled_by', 'fine_note', 'reminded_at'];

    protected $casts = ['issued_at' => 'datetime', 'due_at' => 'datetime', 'returned_at' => 'datetime', 'fine_settled_at' => 'datetime', 'reminded_at' => 'datetime',
        'lost' => 'boolean', 'fine' => 'decimal:2'];

    public function copy(): BelongsTo
    {
        return $this->belongsTo(LibraryCopy::class, 'library_copy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }

    public function isOverdue(): bool
    {
        return $this->returned_at === null && $this->due_at->isPast();
    }

    /** True while the borrower still owes this fine. */
    public function fineUnpaid(): bool
    {
        return $this->fine_status === 'owed' || ($this->fine_status === 'invoiced' && $this->invoice?->status !== 'paid' && $this->invoice?->status !== 'cancelled');
    }
}
