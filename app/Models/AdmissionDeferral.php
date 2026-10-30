<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student who put off starting (or continuing) to a later session; resumed when that session comes. */
class AdmissionDeferral extends Model
{
    protected $fillable = ['student_id', 'from_session_id', 'to_session_id', 'reason', 'previous_status', 'status', 'deferred_by', 'resumed_at', 'resumed_by'];

    protected $casts = ['resumed_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'from_session_id');
    }

    public function toSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'to_session_id');
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deferred_by');
    }
}
