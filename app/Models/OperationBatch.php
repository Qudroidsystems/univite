<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationBatch extends Model
{
    protected $fillable = ['operation_id', 'type', 'module', 'user_id', 'academic_session_id', 'status', 'success_count', 'failed_count', 'skipped_count', 'reason', 'related_operation_id', 'meta'];

    protected $casts = ['meta' => 'array'];

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
