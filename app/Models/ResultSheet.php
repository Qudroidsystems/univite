<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultSheet extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Awaiting approval', 'approved' => 'Approved', 'published' => 'Published'];
    public const BADGES = [
        'draft' => 'bg-secondary-subtle text-secondary', 'submitted' => 'bg-warning-subtle text-warning',
        'approved' => 'bg-info-subtle text-info', 'published' => 'bg-success-subtle text-success',
    ];

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['course_id', 'academic_session_id', 'semester_type_id', 'assessment_scheme_id', 'status', 'stage_id', 'submitted_by', 'submitted_at', 'published_at'];

    protected $casts = ['submitted_at' => 'datetime', 'published_at' => 'datetime'];

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

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ResultStage::class, 'stage_id');
    }

    /** The assessment scheme the sheet's marks are entered in; null = the plain CA / exam split from Result Settings. */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(AssessmentScheme::class, 'assessment_scheme_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(CourseResult::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ResultSheetEvent::class)->latest('id');
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
