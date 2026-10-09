<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeBand extends Model
{
    protected $fillable = ['letter', 'min_score', 'max_score', 'points', 'remark', 'is_pass'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\GradeScale::flush());
        static::deleted(fn () => \App\Support\GradeScale::flush());
    }

    protected $casts = ['min_score' => 'float', 'max_score' => 'float', 'points' => 'float', 'is_pass' => 'boolean'];
}
