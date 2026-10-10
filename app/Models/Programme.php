<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Programme extends Model
{
    protected $fillable = [
        'department_id', 'study_category_id', 'name', 'code', 'degree_type', 'duration_years',
        'min_credit_load', 'max_credit_load', 'graduation_units', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function studyCategory(): BelongsTo
    {
        return $this->belongsTo(StudyCategory::class);
    }

    public function curriculum(): HasMany
    {
        return $this->hasMany(ProgrammeCourse::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /** Written form of a level for this programme, e.g. "200 Level" or "Part 2". */
    public function levelLabel(int $level): string
    {
        return \App\Support\Levels::label($level, $this->studyCategory);
    }

    /** Levels this programme runs: 100 .. duration_years * 100. */
    public function levels(): array
    {
        return array_map(fn ($y) => $y * 100, range(1, max(1, (int) $this->duration_years)));
    }
}
