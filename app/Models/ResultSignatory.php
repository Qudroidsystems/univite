<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** Who signs printed results for a faculty, department, category or programme, e.g. "Dr. J. Edafe, Head of Department". */
class ResultSignatory extends Model
{
    public const SCOPES = ['faculty_id', 'department_id', 'study_category_id', 'programme_id'];

    protected $fillable = ['faculty_id', 'department_id', 'study_category_id', 'programme_id', 'position', 'title', 'name', 'address', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

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

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /** "Dr. J. Edafe" */
    public function fullName(): string
    {
        return trim(($this->title ? $this->title . ' ' : '') . ($this->name ?? ''));
    }

    public function scopeLabel(): string
    {
        return $this->programme?->name ?? $this->department?->name ?? $this->faculty?->name ?? $this->studyCategory?->name ?? 'Every programme';
    }

    /**
     * The signatories for a programme's results, in order. For each position the most specific row wins, so a
     * department's own Head of Department replaces a school-wide one.
     *
     * @return Collection<int, self>
     */
    public static function forProgramme(Programme $programme): Collection
    {
        $programme->loadMissing('department');
        $values = ['faculty_id' => $programme->department?->faculty_id, 'department_id' => $programme->department_id,
            'study_category_id' => $programme->study_category_id, 'programme_id' => $programme->id];

        $query = static::where('is_active', true);
        foreach ($values as $col => $v) {
            $query->where(fn ($q) => $q->whereNull($col)->orWhere($col, $v));
        }

        return $query->get()->groupBy(fn ($s) => strtolower(trim($s->position)))
            ->map(fn ($g) => $g->sortByDesc(fn ($s) => [collect(self::SCOPES)->filter(fn ($c) => $s->{$c} !== null)->count(), $s->id])->first())
            ->sortBy(fn ($s) => [$s->sort_order, $s->id])->values();
    }
}
