<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fault, inspection finding or unsafe condition reported for a venue. A "blocking" one keeps it out of new allocations until resolved. */
class VenueIssue extends Model
{
    public const SEVERITIES = ['minor' => 'Minor', 'major' => 'Major', 'blocking' => 'Venue cannot be used'];
    public const STATUSES = ['open' => 'Open', 'in_progress' => 'Being fixed', 'resolved' => 'Resolved'];

    protected $fillable = ['venue_id', 'title', 'details', 'severity', 'status', 'reported_by', 'resolved_by', 'resolved_at', 'resolution', 'inspected_on'];

    protected $casts = ['resolved_at' => 'datetime', 'inspected_on' => 'date'];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
