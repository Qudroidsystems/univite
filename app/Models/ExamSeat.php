<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One candidate's seat for one paper. */
class ExamSeat extends Model
{
    protected $fillable = ['seating_plan_id', 'timetable_entry_id', 'venue_id', 'course_id', 'student_id', 'slot', 'date', 'starts_at', 'ends_at', 'seat_label', 'seat_row', 'seat_col', 'seat_no', 'code', 'attendance', 'checked_at', 'checked_by', 'moved_from'];

    protected $casts = ['date' => 'date', 'checked_at' => 'datetime'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SeatingPlan::class, 'seating_plan_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** The seat number printed on the slip, e.g. MAIN-HALL · R04-S06 (seat 36). */
    public function getSeatNumberAttribute(): string
    {
        return ($this->venue?->code ? $this->venue->code . '-' : '') . $this->seat_label;
    }
}
