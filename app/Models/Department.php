<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['faculty_id', 'name', 'code', 'hod_id', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function hod(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hod_id');
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
