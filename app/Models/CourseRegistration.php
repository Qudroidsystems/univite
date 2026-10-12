<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseRegistration extends Model
{
    protected $fillable = ['registration_form_id', 'student_id', 'course_id', 'credit_units', 'is_carryover', 'course_status'];

    protected $casts = ['is_carryover' => 'boolean'];

    public function form(): BelongsTo
    {
        return $this->belongsTo(RegistrationForm::class, 'registration_form_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
