<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An invigilation duty: a member of staff in a hall for one sitting. */
class TimetableInvigilator extends Model
{
    protected $fillable = ['timetable_id', 'slot', 'date', 'starts_at', 'ends_at', 'venue_id', 'user_id', 'role'];

    protected $casts = ['date' => 'date'];

    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
