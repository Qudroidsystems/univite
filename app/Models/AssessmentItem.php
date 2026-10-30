<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One assessment in a scheme, for example "Test 1", out of max_score, counting towards CA or exam. max_score may be left
 * blank: the assessment then has no mark of its own, and its marks only have to keep the part within the scheme's CA or
 * exam total.
 */
class AssessmentItem extends Model
{
    public const COMPONENTS = ['ca' => 'Continuous assessment', 'exam' => 'Examination'];

    protected $fillable = ['assessment_scheme_id', 'name', 'code', 'component', 'max_score', 'sort_order'];

    protected $casts = ['max_score' => 'float', 'sort_order' => 'integer'];

    public function isOpen(): bool
    {
        return $this->max_score === null;
    }

    /** The most one mark can be: its own maximum, or the part's whole total when it has none. */
    public function limit(?AssessmentScheme $scheme = null): float
    {
        return $this->max_score ?? ($scheme ?? $this->scheme)->partTotal($this->component);
    }

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(AssessmentScheme::class, 'assessment_scheme_id');
    }

    /** "10", or "any" for an assessment with no mark of its own. */
    public function maxLabel(): string
    {
        return $this->max_score === null ? 'any' : rtrim(rtrim(number_format($this->max_score, 2, '.', ''), '0'), '.');
    }

    /** What the form's "Out of" box shows: the number, or blank. */
    public function maxValue(): string
    {
        return $this->max_score === null ? '' : $this->maxLabel();
    }
}
