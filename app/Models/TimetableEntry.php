<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where and when an item was placed. An examination split over several halls has one row per hall with the same unit_key. */
class TimetableEntry extends Model
{
    protected $fillable = ['timetable_id', 'timetable_item_id', 'course_id', 'unit_key', 'day', 'date', 'starts_at', 'ends_at', 'slots', 'venue_id', 'seats', 'locked'];

    protected $casts = ['slots' => 'array', 'date' => 'date', 'locked' => 'boolean'];

    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TimetableItem::class, 'timetable_item_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function getWhenAttribute(): string
    {
        $day = $this->date ? $this->date->format('D j M Y') : (Timetable::DAYS[$this->day] ?? '');

        return $day . ', ' . substr($this->starts_at, 0, 5) . '–' . substr($this->ends_at, 0, 5);
    }
}
