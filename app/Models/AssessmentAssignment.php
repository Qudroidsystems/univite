<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which scheme the university, a faculty, a department or one course uses, for every session or one session. */
class AssessmentAssignment extends Model
{
    public const SCOPES = ['university' => 'Whole university', 'faculty' => 'Faculty', 'department' => 'Department', 'course' => 'Course'];

    protected $fillable = ['assessment_scheme_id', 'scope_type', 'scope_id', 'academic_session_id', 'assigned_by'];

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(AssessmentScheme::class, 'assessment_scheme_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    /** The faculty, department or course this assignment is for (null for the university). */
    public function scopeModel(): ?Model
    {
        return match ($this->scope_type) {
            'faculty' => Faculty::find($this->scope_id),
            'department' => Department::find($this->scope_id),
            'course' => Course::find($this->scope_id),
            default => null,
        };
    }

    public function scopeLabel(): string
    {
        $m = $this->scopeModel();

        return match ($this->scope_type) {
            'university' => 'Whole university',
            'course' => $m ? "{$m->code} · {$m->title}" : 'Course (removed)',
            default => self::SCOPES[$this->scope_type] . ': ' . ($m?->name ?? '(removed)'),
        };
    }
}
