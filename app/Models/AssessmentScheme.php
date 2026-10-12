<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A named set of assessment items (tests, assignments, practicals, exam ...) that adds up to 100. */
class AssessmentScheme extends Model
{
    protected $fillable = ['name', 'description', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(AssessmentItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssessmentAssignment::class);
    }

    public function sheets(): HasMany
    {
        return $this->hasMany(ResultSheet::class);
    }

    public function caMax(): float
    {
        return round((float) $this->items->where('component', 'ca')->sum('max_score'), 2);
    }

    public function examMax(): float
    {
        return round((float) $this->items->where('component', 'exam')->sum('max_score'), 2);
    }

    /** "Test 1 (10) + Assignment (20) + Exam (70)" */
    public function summary(): string
    {
        return $this->items->map(fn ($i) => $i->name . ' (' . $i->maxLabel() . ')')->implode(' + ');
    }

    /** Items can no longer change once a sheet using the scheme has a mark entered. */
    public function isLocked(): bool
    {
        return AssessmentScore::whereIn('assessment_item_id', $this->items()->select('id'))->exists();
    }
}
