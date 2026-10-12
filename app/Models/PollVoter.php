<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Records that someone has voted in a poll (not what they chose). */
class PollVoter extends Model
{
    public $timestamps = false;

    protected $fillable = ['poll_id', 'user_id', 'voted_at'];

    protected $casts = ['voted_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
