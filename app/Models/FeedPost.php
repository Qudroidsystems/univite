<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** A post on the campus feed, for everyone or one faculty / department; an event when it has a start time. */
class FeedPost extends Model
{
    use SoftDeletes;

    public const AUDIENCES = ['everyone' => 'Everyone', 'students' => 'All students', 'staff' => 'All staff', 'faculty' => 'One faculty', 'department' => 'One department'];

    protected $fillable = ['user_id', 'audience', 'audience_id', 'title', 'body', 'image_path', 'is_event', 'starts_at', 'ends_at', 'venue', 'pinned', 'allow_comments', 'status'];

    protected $casts = ['is_event' => 'boolean', 'pinned' => 'boolean', 'allow_comments' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FeedComment::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function audienceLabel(): string
    {
        return match ($this->audience) {
            'faculty' => Faculty::find($this->audience_id)?->name ?? 'A faculty',
            'department' => Department::find($this->audience_id)?->name ?? 'A department',
            default => self::AUDIENCES[$this->audience] ?? 'Everyone',
        };
    }
}
