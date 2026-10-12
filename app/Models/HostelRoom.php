<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostelRoom extends Model
{
    protected $fillable = ['hostel_id', 'block', 'name', 'floor', 'capacity', 'is_active', 'note'];

    protected $casts = ['is_active' => 'boolean', 'capacity' => 'integer'];

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BedAllocation::class);
    }

    public function label(): string
    {
        return ($this->block ? $this->block . ' ' : '') . 'Room ' . $this->name;
    }
}
