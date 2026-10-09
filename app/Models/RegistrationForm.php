<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegistrationForm extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Awaiting approval', 'approved' => 'Approved', 'rejected' => 'Returned'];
    public const BADGES = [
        'draft' => 'bg-secondary-subtle text-secondary', 'submitted' => 'bg-warning-subtle text-warning',
        'approved' => 'bg-success-subtle text-success', 'rejected' => 'bg-danger-subtle text-danger',
    ];

    protected $attributes = ['status' => 'draft'];

    protected $fillable = ['student_id', 'academic_session_id', 'semester_type_id', 'level', 'status', 'total_units', 'submitted_at', 'decided_by', 'decided_at', 'note'];

    protected $casts = ['submitted_at' => 'datetime', 'decided_at' => 'datetime'];

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

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function courses(): HasMany
    {
        return $this->hasMany(CourseRegistration::class);
    }

    /** Students may add or drop courses only while the form is a draft or has been returned. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'rejected'], true);
    }
}
