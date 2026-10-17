<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = ['department_id', 'study_category_id', 'code', 'title', 'credit_units', 'course_type', 'level', 'semester_type_id', 'academic_session_id', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['course_type' => \App\Support\CourseType::COMPULSORY];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function studyCategory(): BelongsTo
    {
        return $this->belongsTo(StudyCategory::class);
    }

    /** The semester the course is normally taught in. */
    public function semesterType(): BelongsTo
    {
        return $this->belongsTo(SemesterType::class);
    }

    /** The session the course was introduced. */
    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function prerequisites(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_prerequisites', 'course_id', 'prerequisite_id');
    }

    public function programmeCourses(): HasMany
    {
        return $this->hasMany(ProgrammeCourse::class);
    }
}
