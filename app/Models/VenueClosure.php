<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A period when a venue cannot be used (repairs, an event, safety). */
class VenueClosure extends Model
{
    protected $fillable = ['venue_id', 'starts_on', 'ends_on', 'reason', 'created_by', 'lifted_at', 'lifted_by'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'lifted_at' => 'datetime'];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
