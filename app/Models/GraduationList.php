<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The students of one programme checked for graduation in a session. Approving it graduates the eligible ones. */
class GraduationList extends Model
{
    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['programme_id', 'academic_session_id', 'status', 'created_by', 'approved_by', 'checked_at', 'approved_at'];

    protected $casts = ['checked_at' => 'datetime', 'approved_at' => 'datetime'];

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(GraduationEntry::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
