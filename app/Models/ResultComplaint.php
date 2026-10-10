<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's query about one published result. */
class ResultComplaint extends Model
{
    public const STATUSES = ['open' => 'Awaiting review', 'upheld' => 'Upheld', 'rejected' => 'Not upheld'];
    public const BADGES = ['open' => 'bg-warning-subtle text-warning', 'upheld' => 'bg-success-subtle text-success', 'rejected' => 'bg-secondary-subtle text-secondary'];

    protected $attributes = ['status' => 'open'];

    protected $fillable = ['student_id', 'course_result_id', 'reason', 'status', 'response', 'handled_by', 'handled_at'];

    protected $casts = ['handled_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(CourseResult::class, 'course_result_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
