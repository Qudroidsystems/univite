<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A named set of assessment items (tests, assignments, practicals, exam ...) that adds up to 100. */
class AssessmentScheme extends Model
{
    protected $fillable = ['name', 'description', 'ca_total', 'exam_total', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean', 'ca_total' => 'float', 'exam_total' => 'float'];

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

    /** The most a part (ca | exam) can add up to: the total the admin set, or the assessments' own marks added up. */
    public function partTotal(string $component): float
    {
        $set = $component === 'exam' ? $this->exam_total : $this->ca_total;

        return $set !== null ? round((float) $set, 2) : round((float) $this->items->where('component', $component)->sum('max_score'), 2);
    }

    public function caMax(): float
    {
        return $this->partTotal('ca');
    }

    public function examMax(): float
    {
        return $this->partTotal('exam');
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
