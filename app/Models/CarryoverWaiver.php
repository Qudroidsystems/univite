<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** A department's waiver of one carry-over course for one student (see the migration for what it does). */
class CarryoverWaiver extends Model
{
    protected $fillable = ['student_id', 'course_id', 'reason', 'granted_by', 'revoked_at', 'revoked_by', 'revoke_reason'];

    protected $casts = ['revoked_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /** Course ids whose carry-over is waived for the student. @return Collection<int, int> */
    public static function courseIdsFor(int $studentId): Collection
    {
        return static::active()->where('student_id', $studentId)->pluck('course_id')->map(fn ($id) => (int) $id)->unique()->values();
    }
}
