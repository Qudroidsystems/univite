<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = ['department_id', 'code', 'title', 'credit_units', 'level', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function programmeCourses(): HasMany
    {
        return $this->hasMany(ProgrammeCourse::class);
    }
}
