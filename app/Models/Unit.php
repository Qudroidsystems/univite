<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A non-academic office or unit (Bursary, Registry, Works, Library ...) with its head. */
#[ObservedBy(\App\Observers\HeadObserver::class)]
class Unit extends Model
{
    protected $fillable = ['name', 'code', 'head_id', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(StaffProfile::class);
    }
}
