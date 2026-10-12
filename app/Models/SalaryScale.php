<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryScale extends Model
{
    protected $fillable = ['salary_structure_id', 'grade_level', 'step', 'annual_basic'];

    protected $casts = ['grade_level' => 'integer', 'step' => 'integer', 'annual_basic' => 'decimal:2'];

    public function structure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class, 'salary_structure_id');
    }
}
