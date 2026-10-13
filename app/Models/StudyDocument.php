<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Notes, slides or a textbook chapter a student studies with the AI tutor. Private to the student. */
class StudyDocument extends Model
{
    protected $fillable = ['user_id', 'course_id', 'course_material_id', 'title', 'path', 'original_name', 'mime', 'size', 'text', 'chars', 'status', 'error'];

    protected $casts = ['size' => 'integer', 'chars' => 'integer'];

    protected $hidden = ['text'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class, 'course_material_id');
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(StudyQuiz::class);
    }
}
