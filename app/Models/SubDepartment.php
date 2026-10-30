<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A unit inside a department, e.g. Christian Religious Studies inside Religious Studies. */
class SubDepartment extends Model
{
    protected $fillable = ['department_id', 'study_category_id', 'name', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function studyCategory(): BelongsTo
    {
        return $this->belongsTo(StudyCategory::class);
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class);
    }
}
