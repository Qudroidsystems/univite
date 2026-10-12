<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's own earning or deduction for a run of months. A deduction with a balance is a loan: it stops when repaid. */
class StaffPayItem extends Model
{
    protected $fillable = ['user_id', 'kind', 'name', 'amount', 'balance', 'taxable', 'start_month', 'end_month', 'reference', 'note', 'is_active', 'created_by'];

    protected $casts = ['amount' => 'float', 'balance' => 'float', 'taxable' => 'boolean', 'is_active' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLoan(): bool
    {
        return $this->kind === 'deduction' && $this->balance !== null;
    }

    /** Whether it is paid or deducted in a month (YYYY-MM). */
    public function runsIn(string $period): bool
    {
        return $this->is_active && $this->start_month <= $period && ($this->end_month === null || $this->end_month >= $period)
            && (! $this->isLoan() || $this->balance > 0);
    }
}
