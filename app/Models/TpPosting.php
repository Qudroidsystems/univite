<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TpPosting extends Model
{
    public const STATUSES = ['pending' => 'Not posted yet', 'posted' => 'Posted', 'withdrawn' => 'Withdrawn'];

    public const BADGES = ['pending' => 'bg-warning-subtle text-warning', 'posted' => 'bg-success-subtle text-success', 'withdrawn' => 'bg-secondary-subtle text-secondary'];

    protected $fillable = ['tp_exercise_id', 'student_id', 'tp_school_id', 'status', 'choices', 'subject', 'posted_at', 'posted_by', 'final_score'];

    protected $casts = ['choices' => 'array', 'posted_at' => 'datetime', 'final_score' => 'decimal:2'];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(TpExercise::class, 'tp_exercise_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(TpSchool::class, 'tp_school_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(TpAssessment::class)->orderBy('visit_no');
    }
}
