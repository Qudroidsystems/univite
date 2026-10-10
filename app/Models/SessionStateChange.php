<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionStateChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['academic_session_id', 'from_status', 'to_status', 'user_id', 'reason'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
