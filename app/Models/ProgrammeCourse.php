<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgrammeCourse extends Model
{
    /** What sort of course this is in the curriculum. */
    public const KINDS = ['normal' => 'Normal course', 'project' => 'Project', 'teaching_practice' => 'Teaching practice'];

    protected $fillable = ['programme_id', 'course_id', 'level', 'semester_order', 'status', 'kind', 'course_option_id'];

    protected $attributes = ['status' => \App\Support\CourseType::COMPULSORY, 'kind' => 'normal'];

    /** The course option this entry is for; null = every student on the programme. */
    public function courseOption(): BelongsTo
    {
        return $this->belongsTo(CourseOption::class);
    }

    /** Whether a student on the programme takes this entry (it is for everyone, or for their option). */
    public function appliesTo(Student $student): bool
    {
        return $this->course_option_id === null || $this->course_option_id === $student->course_option_id;
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
