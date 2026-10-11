<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Days carried over from last year, or a recorded adjustment (with a reason) to someone's balance. */
class LeaveLedgerEntry extends Model
{
    protected $table = 'leave_ledger';

    protected $fillable = ['user_id', 'leave_type_id', 'year', 'kind', 'days', 'note', 'created_by'];

    protected $casts = ['days' => 'float'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
