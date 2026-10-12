<?php

namespace App\Models;

use App\Data\Campus\Audience;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A poll, survey or election: who may take part, when voting is open, and when the results are shown. */
class Poll extends Model
{
    public const KINDS = ['poll' => 'Poll or survey', 'election' => 'Election'];
    public const RESULTS = [
        'live' => 'While voting is open',
        'after_close' => 'As soon as voting closes',
        'on_publish' => 'Only when the results are published',
    ];

    protected $fillable = ['title', 'description', 'kind', 'audience', 'opens_at', 'closes_at', 'is_secret', 'results_shown', 'status', 'results_published_at',
        'eligible_count', 'created_by', 'published_by', 'published_at', 'closed_at'];

    protected $casts = ['audience' => 'array', 'opens_at' => 'datetime', 'closes_at' => 'datetime', 'is_secret' => 'boolean', 'results_published_at' => 'datetime',
        'published_at' => 'datetime', 'closed_at' => 'datetime', 'eligible_count' => 'integer'];

    public function questions(): HasMany
    {
        return $this->hasMany(PollQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function voters(): HasMany
    {
        return $this->hasMany(PollVoter::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(PollAnswer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function audienceData(): Audience
    {
        return Audience::fromArray($this->audience);
    }

    public function isElection(): bool
    {
        return $this->kind === 'election';
    }

    /** draft | scheduled | open | closed */
    public function phase(): string
    {
        if ($this->status === 'draft') {
            return 'draft';
        }
        if ($this->status === 'closed' || now()->gte($this->closes_at)) {
            return 'closed';
        }

        return now()->lt($this->opens_at) ? 'scheduled' : 'open';
    }

    public function isOpen(): bool
    {
        return $this->phase() === 'open';
    }

    public function phaseBadge(): array
    {
        return match ($this->phase()) {
            'draft' => ['Draft', 'bg-secondary-subtle text-secondary'],
            'scheduled' => ['Opens ' . $this->opens_at->format('j M, g:ia'), 'bg-info-subtle text-info'],
            'open' => ['Open until ' . $this->closes_at->format('j M, g:ia'), 'bg-success-subtle text-success'],
            default => ['Closed', 'bg-dark-subtle text-dark'],
        };
    }

    /** May people who took part see the results now? (Staff with "View polls" always can.) */
    public function resultsVisible(): bool
    {
        return match ($this->results_shown) {
            'live' => $this->phase() !== 'draft',
            'after_close' => $this->phase() === 'closed',
            default => $this->results_published_at !== null,
        };
    }

    /** Questions and candidates can only change while nobody has voted. */
    public function isLocked(): bool
    {
        return $this->voters()->exists();
    }
}
