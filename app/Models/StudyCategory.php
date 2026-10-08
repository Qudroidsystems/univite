<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of award a university runs, e.g. Degree, NCE, Part-time, Sandwich, Diploma. */
class StudyCategory extends Model
{
    protected $fillable = ['name', 'code', 'duration_years', 'level_format', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class);
    }
}
