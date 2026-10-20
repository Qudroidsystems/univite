<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student allowed (or barred) for one area for a session regardless of their fees. */
class FeeAccessOverride extends Model
{
    protected $fillable = ['student_id', 'academic_session_id', 'area', 'allow', 'reason', 'expires_on', 'created_by'];

    protected $casts = ['allow' => 'boolean', 'expires_on' => 'date'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inForce(): bool
    {
        return ! $this->expires_on || $this->expires_on->gte(today());
    }
}
