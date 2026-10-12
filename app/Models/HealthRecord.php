<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's health record. Allergies, conditions, medications, disabilities and the fitness note are encrypted. */
class HealthRecord extends Model
{
    public const FITNESS = ['fit' => 'Fit', 'fit_with_conditions' => 'Fit, with conditions', 'unfit' => 'Not fit'];

    public const FITNESS_BADGES = ['fit' => 'bg-success-subtle text-success', 'fit_with_conditions' => 'bg-warning-subtle text-warning', 'unfit' => 'bg-danger-subtle text-danger'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allergies' => 'encrypted', 'conditions' => 'encrypted', 'medications' => 'encrypted', 'disabilities' => 'encrypted', 'fitness_note' => 'encrypted',
            'examined_at' => 'datetime', 'self_updated_at' => 'datetime', 'height_cm' => 'float', 'weight_kg' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'examined_by');
    }

    public function examSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'exam_session_id');
    }

    public function isExamined(): bool
    {
        return $this->examined_at !== null && $this->fitness !== null;
    }

    public function bmi(): ?float
    {
        return $this->height_cm && $this->weight_kg ? round($this->weight_kg / (($this->height_cm / 100) ** 2), 1) : null;
    }
}
