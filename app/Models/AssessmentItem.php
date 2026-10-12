<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One assessment in a scheme, for example "Test 1", out of max_score, counting towards CA or exam. */
class AssessmentItem extends Model
{
    public const COMPONENTS = ['ca' => 'Continuous assessment', 'exam' => 'Examination'];

    protected $fillable = ['assessment_scheme_id', 'name', 'code', 'component', 'max_score', 'sort_order'];

    protected $casts = ['max_score' => 'float', 'sort_order' => 'integer'];

    public function scheme(): BelongsTo
    {
        return $this->belongsTo(AssessmentScheme::class, 'assessment_scheme_id');
    }

    public function maxLabel(): string
    {
        return rtrim(rtrim(number_format($this->max_score, 2, '.', ''), '0'), '.');
    }
}
