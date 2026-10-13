<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's weekly reading timetable for one semester, built by App\Services\Study\ReadingPlanner. */
class StudyPlan extends Model
{
    protected $fillable = ['user_id', 'academic_session_id', 'semester_type_id', 'preferences', 'sessions', 'summary', 'generated_at'];

    protected $casts = ['preferences' => 'array', 'sessions' => 'array', 'summary' => 'array', 'generated_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
