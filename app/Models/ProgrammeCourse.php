<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgrammeCourse extends Model
{
    public const STATUSES = ['core' => 'Core', 'required' => 'Required', 'elective' => 'Elective'];
    public const SEMESTERS = [1 => 'First Semester', 2 => 'Second Semester'];

    protected $fillable = ['programme_id', 'course_id', 'level', 'semester_order', 'status'];

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
