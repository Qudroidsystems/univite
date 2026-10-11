<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A time a lecturer or invigilator cannot be scheduled: weekly (a day) or for a range of dates, all day or between two times. */
class StaffUnavailability extends Model
{
    protected $table = 'staff_unavailability';

    protected $fillable = ['user_id', 'day', 'date_from', 'date_to', 'starts_at', 'ends_at', 'reason', 'created_by'];

    protected $casts = ['date_from' => 'date', 'date_to' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
