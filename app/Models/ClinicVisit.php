<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attendance at the health centre. The clinical notes are encrypted. */
class ClinicVisit extends Model
{
    public const CATEGORIES = ['illness' => 'Illness', 'injury' => 'Injury', 'check_up' => 'Check-up', 'follow_up' => 'Follow-up', 'emergency' => 'Emergency', 'other' => 'Other'];

    public const OUTCOMES = ['treated' => 'Treated and sent home', 'observation' => 'Kept for observation', 'referred' => 'Referred', 'admitted' => 'Admitted'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'seen_at' => 'datetime', 'excused_from' => 'date', 'excused_to' => 'date',
            'complaint' => 'encrypted', 'findings' => 'encrypted', 'diagnosis' => 'encrypted', 'treatment' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seen_by');
    }

    public function hasExcuse(): bool
    {
        return $this->excused_from !== null && $this->excuse_token !== null;
    }
}
