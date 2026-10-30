<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A matric number format for part of the school: a faculty, department, study category, programme and/or entry mode.
 * The most specific active format that matches a student wins; the school-wide pattern is used when none does.
 */
class MatricFormat extends Model
{
    protected $fillable = ['name', 'faculty_id', 'department_id', 'study_category_id', 'programme_id', 'entry_mode', 'pattern', 'prefix', 'suffix', 'serial_from', 'serial_to', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'serial_from' => 'integer', 'serial_to' => 'integer'];

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function studyCategory(): BelongsTo
    {
        return $this->belongsTo(StudyCategory::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /** How specific this format is: a programme beats a department, which beats a faculty; a category or entry mode adds to it. */
    public function weight(): int
    {
        return ($this->programme_id ? 8 : 0) + ($this->department_id ? 4 : 0) + ($this->faculty_id ? 2 : 0) + ($this->study_category_id ? 1 : 0) + ($this->entry_mode ? 16 : 0);
    }

    public function matches(Programme $programme, ?string $entryMode): bool
    {
        $programme->loadMissing('department');

        return (! $this->programme_id || $this->programme_id === $programme->id)
            && (! $this->department_id || $this->department_id === $programme->department_id)
            && (! $this->faculty_id || $this->faculty_id === $programme->department?->faculty_id)
            && (! $this->study_category_id || $this->study_category_id === $programme->study_category_id)
            && (! $this->entry_mode || $this->entry_mode === $entryMode);
    }
}
