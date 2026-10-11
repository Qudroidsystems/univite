<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One SMS or WhatsApp message: what was sent, to whom, through which provider, and whether it arrived at the provider. */
class OutboundMessage extends Model
{
    protected $fillable = ['user_id', 'channel', 'recipient', 'body', 'topic', 'subject_type', 'subject_id', 'dedupe_key', 'status', 'provider', 'provider_ref', 'error', 'attempts', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
