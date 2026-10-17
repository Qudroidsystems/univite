<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row of the key to grade: a score range, its letter, points and rank, for the whole university or one school, department and/or category. */
class GradeBand extends Model
{
    use Concerns\HasKeyScope;

    public const RANKS = ['Distinction', 'Credit', 'Merit', 'Pass', 'Fail'];

    protected $fillable = ['faculty_id', 'department_id', 'study_category_id', 'letter', 'min_score', 'max_score', 'points', 'rank', 'remark', 'is_pass'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\GradeScale::flush());
        static::deleted(fn () => \App\Support\GradeScale::flush());
    }

    protected $casts = ['faculty_id' => 'integer', 'department_id' => 'integer', 'study_category_id' => 'integer', 'min_score' => 'float', 'max_score' => 'float', 'points' => 'float', 'is_pass' => 'boolean'];
}
