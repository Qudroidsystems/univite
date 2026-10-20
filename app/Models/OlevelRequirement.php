<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What a department or programme asks for in O Level results before its students may register courses. */
class OlevelRequirement extends Model
{
    public const APPLIES = ['entry' => 'Only when registering at the admission level', 'all' => 'At every level'];

    protected $fillable = ['scope_type', 'scope_id', 'min_credits', 'max_sittings', 'optional_needed', 'applies_to', 'is_active', 'note', 'updated_by'];

    protected $casts = ['is_active' => 'boolean', 'min_credits' => 'integer', 'max_sittings' => 'integer', 'optional_needed' => 'integer'];

    protected $attributes = ['min_credits' => 5, 'max_sittings' => 2, 'optional_needed' => 0, 'applies_to' => 'entry', 'is_active' => true];

    public function subjects(): HasMany
    {
        return $this->hasMany(OlevelRequirementSubject::class)->orderBy('kind')->orderBy('sort_order')->orderBy('subject');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeName(): string
    {
        return ($this->scope_type === 'programme' ? Programme::whereKey($this->scope_id)->value('name') : Department::whereKey($this->scope_id)->value('name')) ?? '—';
    }

    /** "English Language (C6), Mathematics (C6) and 2 of Biology, Chemistry, Physics; 5 credits in at most 2 sittings." */
    public function summary(): string
    {
        $this->loadMissing('subjects');
        $req = $this->subjects->where('kind', 'required')->map(fn ($s) => "{$s->subject} ({$s->min_grade})")->values();
        $opt = $this->subjects->where('kind', 'optional');
        $parts = $req->all();
        if ($opt->isNotEmpty() && $this->optional_needed > 0) {
            $parts[] = "{$this->optional_needed} of " . $opt->map(fn ($s) => "{$s->subject} ({$s->min_grade})")->implode(', ');
        }
        $what = $parts ? implode(', ', $parts) . '; ' : '';

        return $what . "{$this->min_credits} credits in at most {$this->max_sittings} " . ($this->max_sittings === 1 ? 'sitting' : 'sittings') . '.';
    }
}
