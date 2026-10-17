<?php

namespace App\Models\Concerns;

use App\Data\Results\KeyContext;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\StudyCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A key row (key to grade, key to result) for the whole university, or for one school, department and/or student category. */
trait HasKeyScope
{
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function studyCategory(): BelongsTo
    {
        return $this->belongsTo(StudyCategory::class);
    }

    /** Whether this row applies in $c. */
    public function appliesTo(KeyContext $c): bool
    {
        return ($this->department_id === null || $this->department_id === $c->departmentId)
            && ($this->faculty_id === null || $this->faculty_id === $c->facultyId)
            && ($this->study_category_id === null || $this->study_category_id === $c->categoryId);
    }

    /** How specific the row is: a department beats a school, which beats a category, which beats the whole university. */
    public function specificity(): int
    {
        return ($this->department_id ? 4 : 0) + ($this->faculty_id ? 2 : 0) + ($this->study_category_id ? 1 : 0);
    }

    /** The part of the university this row covers. */
    public function coverageKey(): string
    {
        return ($this->faculty_id ?? '-') . ':' . ($this->department_id ?? '-') . ':' . ($this->study_category_id ?? '-');
    }

    /** "Whole university", or e.g. "Science Education · NCE". */
    public function coverageLabel(): string
    {
        $parts = array_filter([$this->department?->name ?? $this->faculty?->name, $this->studyCategory?->name]);

        return $parts ? implode(' · ', $parts) : 'Whole university';
    }

    /** Rows for exactly the same school, department and category. */
    public function scopeSameScope(Builder $q, ?int $faculty, ?int $department, ?int $category): Builder
    {
        foreach (['faculty_id' => $faculty, 'department_id' => $department, 'study_category_id' => $category] as $col => $v) {
            $v === null ? $q->whereNull($col) : $q->where($col, $v);
        }

        return $q;
    }

    /**
     * Of $rows, the set that applies in $c: the most specific scope that has rows matching. Rows of different
     * scopes are never mixed, so a department's own key replaces the university's rather than adding to it.
     *
     * @template T
     * @param  \Illuminate\Support\Collection<int, T>  $rows
     * @return \Illuminate\Support\Collection<int, T>
     */
    public static function pick(\Illuminate\Support\Collection $rows, ?KeyContext $c): \Illuminate\Support\Collection
    {
        $c ??= new KeyContext;
        $matching = $rows->filter(fn ($r) => $r->appliesTo($c));
        if ($matching->isEmpty()) {
            return $matching;
        }
        $best = $matching->max(fn ($r) => $r->specificity());
        $scope = $matching->first(fn ($r) => $r->specificity() === $best)->coverageKey();

        return $matching->filter(fn ($r) => $r->coverageKey() === $scope)->values();
    }
}
