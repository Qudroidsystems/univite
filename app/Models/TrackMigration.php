<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One "move to the next semester" run for a class or a student. */
class TrackMigration extends Model
{
    protected $fillable = ['key', 'from_session_id', 'from_semester_type_id', 'to_session_id', 'to_semester_type_id', 'scope', 'moved', 'held', 'created_by', 'undone_at', 'undone_by'];

    protected $casts = ['scope' => 'array', 'undone_at' => 'datetime'];

    public function tracks(): HasMany
    {
        return $this->hasMany(StudentTrack::class);
    }

    public function toSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'to_session_id');
    }

    public function toSemester(): BelongsTo
    {
        return $this->belongsTo(SemesterType::class, 'to_semester_type_id');
    }

    public function fromSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'from_session_id');
    }

    public function fromSemester(): BelongsTo
    {
        return $this->belongsTo(SemesterType::class, 'from_semester_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
