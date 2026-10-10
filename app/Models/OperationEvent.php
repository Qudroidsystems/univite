<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** An audit event. It can be added but never changed or removed: a correction is a new event pointing at the old one. */
class OperationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['operation_batch_id', 'user_id', 'action', 'subject_type', 'subject_id', 'label', 'before', 'after', 'note', 'corrects_event_id'];

    protected $casts = ['before' => 'array', 'after' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events cannot be changed. Add a correcting event instead.'));
        static::deleting(fn () => throw new LogicException('Audit events cannot be deleted. Add a correcting event instead.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OperationBatch::class, 'operation_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
