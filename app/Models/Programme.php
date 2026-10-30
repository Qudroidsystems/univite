<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Programme extends Model
{
    protected $fillable = [
        'department_id', 'sub_department_id', 'study_category_id', 'name', 'code', 'degree_type', 'duration_years',
        'min_credit_load', 'max_credit_load', 'graduation_units', 'is_active',
        'is_combination', 'second_department_id', 'show_on_application', 'application_name',
    ];

    protected $casts = ['is_active' => 'boolean', 'is_combination' => 'boolean', 'show_on_application' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function subDepartment(): BelongsTo
    {
        return $this->belongsTo(SubDepartment::class);
    }

    /** The other department of a combined programme (e.g. English in "Social Studies/English"). */
    public function secondDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'second_department_id');
    }

    public function courseOptions(): HasMany
    {
        return $this->hasMany(CourseOption::class);
    }

    /** How the programme reads on the application form, e.g. "B.A.Ed. Social Studies/English". */
    public function applicationLabel(): string
    {
        return $this->application_name ?: $this->name;
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

    /**
     * Levels this programme runs: 100 .. duration_years * 100, narrowed to the lowest and highest level its department
     * runs for its study category (Department Categories), when that is set.
     */
    public function levels(): array
    {
        $levels = array_map(fn ($y) => $y * 100, range(1, max(1, (int) $this->duration_years)));
        $span = DepartmentCategory::for($this->department_id, $this->study_category_id);
        if ($span) {
            $top = $span->highest_level ?: max($levels);
            $levels = range(max(100, $span->lowest_level), max($span->lowest_level, $top), 100);
        }

        return $levels;
    }
}
