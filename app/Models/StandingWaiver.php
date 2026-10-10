<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lets a student below the withdrawal CGPA register for one session. */
class StandingWaiver extends Model
{
    protected $fillable = ['student_id', 'academic_session_id', 'note', 'granted_by'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
