<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMember extends Model
{
    protected $fillable = ['chat_conversation_id', 'user_id', 'role', 'last_read_message_id', 'muted', 'auto'];

    protected $casts = ['muted' => 'boolean', 'auto' => 'boolean', 'last_read_message_id' => 'integer'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
