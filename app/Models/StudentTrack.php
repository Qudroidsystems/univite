<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where a student is in one semester: session, semester, level, programme and option, and how that semester went. */
class StudentTrack extends Model
{
    protected $fillable = ['student_id', 'track_migration_id', 'closed_by_migration_id', 'academic_session_id', 'semester_type_id', 'level', 'programme_id', 'course_option_id', 'status',
        'semester_remark', 'cumulative_remark', 'result_action', 'computed_result_id', 'previous_level', 'previous_semester_type_id', 'created_by'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function semesterType(): BelongsTo
    {
        return $this->belongsTo(SemesterType::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }
}
