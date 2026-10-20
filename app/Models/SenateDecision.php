<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Senate's approve / defer / refer on a student or a course for a session. The latest decision on a subject counts. */
class SenateDecision extends Model
{
    public const DECISIONS = ['approved' => 'Approved', 'deferred' => 'Deferred', 'referred' => 'Referred back'];

    public const BADGES = ['approved' => 'bg-success', 'deferred' => 'bg-warning', 'referred' => 'bg-danger'];

    protected $fillable = ['academic_session_id', 'semester_type_id', 'subject_type', 'subject_id', 'programme_id', 'level', 'decision', 'note', 'status_seen', 'reasons', 'decided_by'];

    protected $casts = ['reasons' => 'array'];

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
