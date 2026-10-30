<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A study category a department runs (e.g. NCE in Economics), with the levels it covers and semesters per level. */
class DepartmentCategory extends Model
{
    protected $fillable = ['department_id', 'study_category_id', 'lowest_level', 'highest_level', 'semesters_per_level', 'allow_combination', 'is_active'];

    protected $casts = ['allow_combination' => 'boolean', 'is_active' => 'boolean', 'lowest_level' => 'integer', 'highest_level' => 'integer', 'semesters_per_level' => 'integer'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function studyCategory(): BelongsTo
    {
        return $this->belongsTo(StudyCategory::class);
    }

    public static function for(?int $departmentId, ?int $categoryId): ?self
    {
        return $departmentId && $categoryId ? static::where('department_id', $departmentId)->where('study_category_id', $categoryId)->where('is_active', true)->first() : null;
    }
}
