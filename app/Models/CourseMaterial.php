<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Notes, slides or a link a lecturer shares with the students of a course. */
class CourseMaterial extends Model
{
    use SoftDeletes;

    protected $fillable = ['course_id', 'academic_session_id', 'user_id', 'title', 'description', 'path', 'link', 'original_name', 'mime', 'size', 'downloads'];

    protected $casts = ['size' => 'integer', 'downloads' => 'integer'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }
}
