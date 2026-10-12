<?php

namespace App\Models;

use App\Data\Campus\Audience;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One teaching practice exercise: who goes, to which schools, who supervises, and how lessons are marked. */
class TpExercise extends Model
{
    /** A starting marking guide; each exercise keeps its own copy and can change it. */
    public const DEFAULT_CRITERIA = [
        ['key' => 'lesson_plan', 'label' => 'Lesson plan', 'max' => 20],
        ['key' => 'introduction', 'label' => 'Introduction', 'max' => 10],
        ['key' => 'presentation', 'label' => 'Presentation and mastery of content', 'max' => 30],
        ['key' => 'class_management', 'label' => 'Class management', 'max' => 15],
        ['key' => 'materials', 'label' => 'Use of instructional materials', 'max' => 10],
        ['key' => 'evaluation', 'label' => 'Evaluation and conclusion', 'max' => 15],
    ];

    protected $fillable = ['name', 'academic_session_id', 'semester_type_id', 'course_id', 'audience', 'starts_on', 'ends_on', 'visits_required', 'criteria',
        'choices_open', 'max_choices', 'letter_text', 'scores_published_at', 'created_by'];

    protected $casts = ['audience' => 'array', 'criteria' => 'array', 'starts_on' => 'date', 'ends_on' => 'date', 'choices_open' => 'boolean', 'scores_published_at' => 'datetime'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function semesterType(): BelongsTo
    {
        return $this->belongsTo(SemesterType::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(TpSchool::class, 'tp_exercise_schools')->withPivot('capacity')->withTimestamps()->orderBy('name');
    }

    public function supervisors(): HasMany
    {
        return $this->hasMany(TpSupervisor::class);
    }

    public function postings(): HasMany
    {
        return $this->hasMany(TpPosting::class);
    }

    public function audienceData(): Audience
    {
        return Audience::fromArray(($this->audience ?? []) + ['who' => 'students']);
    }

    public function maxMarks(): int
    {
        return (int) collect($this->criteria)->sum('max');
    }
}
