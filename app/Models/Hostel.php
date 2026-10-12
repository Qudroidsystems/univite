<?php

namespace App\Models;

use App\Data\Campus\Audience;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Hostel extends Model
{
    public const GENDERS = ['male' => 'Male', 'female' => 'Female', 'mixed' => 'Mixed'];

    protected $fillable = ['name', 'code', 'gender', 'audience', 'description', 'warden_id', 'is_active', 'sort_order'];

    protected $casts = ['audience' => 'array', 'is_active' => 'boolean'];

    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class)->orderBy('block')->orderByRaw('length(name)')->orderBy('name');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(HostelRate::class);
    }

    public function allocations(): HasManyThrough
    {
        return $this->hasManyThrough(BedAllocation::class, HostelRoom::class);
    }

    public function warden(): BelongsTo
    {
        return $this->belongsTo(User::class, 'warden_id');
    }

    public function audienceData(): Audience
    {
        return Audience::fromArray(($this->audience ?? []) + ['who' => 'students']);
    }

    public function rateFor(?AcademicSession $session): float
    {
        return $session ? (float) ($this->rates->firstWhere('academic_session_id', $session->id)?->amount ?? 0) : 0.0;
    }
}
