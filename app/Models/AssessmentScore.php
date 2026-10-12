<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's mark for one assessment item on a score sheet. */
class AssessmentScore extends Model
{
    protected $fillable = ['course_result_id', 'assessment_item_id', 'score', 'entered_by'];

    protected $casts = ['score' => 'float'];

    public function result(): BelongsTo
    {
        return $this->belongsTo(CourseResult::class, 'course_result_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AssessmentItem::class, 'assessment_item_id');
    }
}
