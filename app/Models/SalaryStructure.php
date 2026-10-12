<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A salary structure (CONUASS, CONTISS ...): grade levels and steps with an annual basic salary for each. */
class SalaryStructure extends Model
{
    protected $fillable = ['code', 'name', 'max_grade', 'max_step', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'max_grade' => 'integer', 'max_step' => 'integer'];

    public function scales(): HasMany
    {
        return $this->hasMany(SalaryScale::class);
    }

    /** Annual basic salary for a grade level and step, or null when the scale has no amount for it. */
    public function annualBasic(int $grade, int $step): ?float
    {
        $v = $this->relationLoaded('scales')
            ? $this->scales->first(fn ($s) => $s->grade_level === $grade && $s->step === $step)?->annual_basic
            : $this->scales()->where(['grade_level' => $grade, 'step' => $step])->value('annual_basic');

        return $v === null ? null : (float) $v;
    }
}
