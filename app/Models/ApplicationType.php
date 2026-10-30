<?php

namespace App\Models;

use App\Services\ApplicationSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/** A kind of application the school runs, e.g. Degree (UTME), PDE or NCE Part-Time, each with its own fee and dates. */
class ApplicationType extends Model
{
    protected $fillable = ['name', 'study_category_id', 'fee', 'opens_on', 'closes_on', 'needs_jamb', 'instructions', 'is_active'];

    protected $casts = ['fee' => 'decimal:2', 'opens_on' => 'date', 'closes_on' => 'date', 'needs_jamb' => 'boolean', 'is_active' => 'boolean'];

    public function studyCategory(): BelongsTo
    {
        return $this->belongsTo(StudyCategory::class);
    }

    public function isOpen(): bool
    {
        $today = now()->startOfDay();

        return $this->is_active && (! $this->opens_on || ! $today->lt($this->opens_on)) && (! $this->closes_on || ! $today->gt($this->closes_on));
    }

    /** The screening fee for this kind of application. */
    public function screeningFee(): float
    {
        return $this->fee !== null ? (float) $this->fee : ApplicationSettings::fee();
    }

    /** Whether a programme can be applied for under this type. */
    public function allows(Programme $programme): bool
    {
        return ! $this->study_category_id || (int) $programme->study_category_id === (int) $this->study_category_id;
    }

    /** @return Collection<int, self> the types applicants can choose now */
    public static function open(): Collection
    {
        return static::with('studyCategory')->where('is_active', true)->orderBy('name')->get()->filter->isOpen()->values();
    }
}
