<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One question of a poll, or one office of an election. */
class PollQuestion extends Model
{
    public const TYPES = ['single' => 'Choose one', 'multiple' => 'Choose several', 'text' => 'Written answer'];

    protected $fillable = ['poll_id', 'prompt', 'type', 'max_choices', 'is_required', 'sort_order'];

    protected $casts = ['is_required' => 'boolean', 'max_choices' => 'integer'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
