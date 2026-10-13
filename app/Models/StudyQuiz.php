<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Practice questions generated from a study document, with the student's answers and marks. */
class StudyQuiz extends Model
{
    protected $fillable = ['user_id', 'study_document_id', 'course_id', 'title', 'difficulty', 'questions', 'answers', 'score'];

    protected $casts = ['questions' => 'array', 'answers' => 'array', 'score' => 'float'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(StudyDocument::class, 'study_document_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
