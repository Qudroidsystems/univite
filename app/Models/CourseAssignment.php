<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseAssignment extends Model
{
    protected $fillable = ['user_id', 'course_id', 'academic_session_id', 'semester_type_id'];

    protected static function booted(): void
    {
        // The person a course is defined for must be able to enter its scores.
        static::saved(fn (self $a) => $a->user && app(\App\Services\Access\LecturerRole::class)->ensure($a->user));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function semesterType(): BelongsTo
    {
        return $this->belongsTo(SemesterType::class);
    }
}
