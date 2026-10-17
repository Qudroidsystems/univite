<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One create, change or delete of a record by a signed-in person (see App\Listeners\RecordModelActivity). */
class RecordActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['subject_type', 'subject_id', 'user_id', 'action', 'fields', 'label', 'ip'];

    protected $casts = ['fields' => 'array', 'created_at' => 'datetime'];

    public const ACTIONS = ['created' => 'Created', 'updated' => 'Changed', 'deleted' => 'Deleted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
