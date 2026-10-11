<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseResult extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'published' => 'Published'];

    protected $fillable = ['result_sheet_id', 'legacy_import_id', 'student_id', 'course_id', 'academic_session_id', 'semester_type_id', 'ca', 'exam', 'total', 'grade', 'points', 'is_pass', 'credit_units', 'status'];

    protected $casts = ['ca' => 'float', 'exam' => 'float', 'total' => 'float', 'points' => 'float', 'is_pass' => 'boolean'];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(ResultSheet::class, 'result_sheet_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
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
