<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationBatch extends Model
{
    protected $fillable = ['operation_id', 'type', 'module', 'user_id', 'academic_session_id', 'status', 'success_count', 'failed_count', 'skipped_count', 'reason', 'related_operation_id', 'meta'];

    protected $casts = ['meta' => 'array'];

    /** Once written, only the outcome (status and counts) may change. A batch is never deleted. */
    private const MUTABLE = ['status', 'success_count', 'failed_count', 'skipped_count', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $b) {
            if (array_diff(array_keys($b->getDirty()), self::MUTABLE)) {
                throw new \LogicException('An operation\'s record cannot be changed. Record a new operation instead.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Operations cannot be deleted from the history.'));
    }

    /** Operations that reverse or correct this one. */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'related_operation_id')->latest('id');
    }

    public function label(): string
    {
        return \App\Support\Reversals::typeLabel($this->type);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function related(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_operation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OperationEvent::class)->orderBy('id');
    }
}
