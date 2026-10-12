<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone reported a chat message, listing, post, comment or person for the school to look at. */
class ContentReport extends Model
{
    public const TYPES = ['message' => ChatMessage::class, 'listing' => MarketListing::class, 'post' => FeedPost::class, 'comment' => FeedComment::class, 'user' => User::class];

    public const REASONS = ['spam' => 'Spam or scam', 'abuse' => 'Harassment or bullying', 'hate' => 'Hate speech', 'sexual' => 'Sexual content', 'violence' => 'Violence or threats',
        'fraud' => 'Fraud or stolen goods', 'prohibited' => 'Item not allowed on campus', 'other' => 'Something else'];

    protected $fillable = ['reportable_type', 'reportable_id', 'user_id', 'reason', 'details', 'status', 'handled_by', 'handled_at', 'outcome'];

    protected $casts = ['handled_at' => 'datetime'];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** The reported thing, including deleted ones. */
    public function target(): ?Model
    {
        $class = self::TYPES[$this->reportable_type] ?? null;
        if (! $class) {
            return null;
        }
        $q = $class::query();
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($class), true)) {
            $q->withTrashed();
        }

        return $q->find($this->reportable_id);
    }
}
