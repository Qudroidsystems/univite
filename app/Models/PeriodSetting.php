<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A session's (or one of its semesters') own values for a group of settings; see App\Services\RegistrationSettings. */
class PeriodSetting extends Model
{
    protected $fillable = ['academic_session_id', 'semester_type_id', 'group', 'values', 'updated_by'];

    protected $casts = ['values' => 'array'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function semesterType(): BelongsTo
    {
        return $this->belongsTo(SemesterType::class);
    }

    public function scopeFor(Builder $q, int $sessionId, ?int $semesterTypeId, string $group = 'registration'): Builder
    {
        return $q->where('academic_session_id', $sessionId)->where('group', $group)
            ->when($semesterTypeId, fn ($w) => $w->where('semester_type_id', $semesterTypeId), fn ($w) => $w->whereNull('semester_type_id'));
    }

    public function label(): string
    {
        return trim(($this->session?->name ?? '') . ($this->semesterType ? ' · ' . $this->semesterType->name : ''));
    }
}
