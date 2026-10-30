<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student moved from one programme (and option, and level) to another, with why and by whom. */
class StudentTransfer extends Model
{
    protected $fillable = ['student_id', 'from_programme_id', 'to_programme_id', 'from_course_option_id', 'to_course_option_id', 'from_level', 'to_level', 'academic_session_id', 'reason', 'transferred_by'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromProgramme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'from_programme_id');
    }

    public function toProgramme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'to_programme_id');
    }

    public function by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
