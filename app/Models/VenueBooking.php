<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-off use of a venue (meeting, seminar, event) outside the timetables. */
class VenueBooking extends Model
{
    protected $fillable = ['venue_id', 'title', 'date', 'starts_at', 'ends_at', 'attendees', 'booked_by', 'status', 'note'];

    protected $casts = ['date' => 'date'];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }
}
