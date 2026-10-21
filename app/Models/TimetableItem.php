<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Something the timetable has to place: a course's lectures (n meetings a week of d periods) or its examination paper. */
class TimetableItem extends Model
{
    public const ACTIVITIES = ['lecture' => 'Lecture', 'tutorial' => 'Tutorial', 'practical' => 'Practical', 'exam' => 'Examination'];

    protected $fillable = ['timetable_id', 'course_id', 'activity', 'group_label', 'students', 'meetings', 'duration', 'venue_type', 'facilities', 'staff', 'preferred_venue_id', 'priority', 'issues', 'is_active'];

    protected $casts = ['facilities' => 'array', 'staff' => 'array', 'issues' => 'array', 'is_active' => 'boolean'];

    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function preferredVenue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'preferred_venue_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function getLabelAttribute(): string
    {
        $code = $this->course?->code ?? 'Course';
        $suffix = $this->activity !== 'lecture' && $this->activity !== 'exam' ? ' ' . self::ACTIVITIES[$this->activity] : '';

        return $code . $suffix . ($this->group_label ? ' (' . $this->group_label . ')' : '');
    }
}
