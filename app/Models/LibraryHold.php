<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryHold extends Model
{
    public const STATUSES = ['waiting' => 'Waiting', 'ready' => 'Ready to collect', 'fulfilled' => 'Collected', 'cancelled' => 'Cancelled', 'expired' => 'Not collected in time'];

    protected $fillable = ['library_item_id', 'user_id', 'library_copy_id', 'status', 'ready_at', 'expires_at'];

    protected $casts = ['ready_at' => 'datetime', 'expires_at' => 'datetime'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'library_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function copy(): BelongsTo
    {
        return $this->belongsTo(LibraryCopy::class, 'library_copy_id');
    }
}
