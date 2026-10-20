<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentOlevelResult extends Model
{
    protected $fillable = ['student_olevel_sitting_id', 'subject', 'grade', 'extracted_grade', 'confirmed_grade'];

    public function sitting(): BelongsTo
    {
        return $this->belongsTo(StudentOlevelSitting::class, 'student_olevel_sitting_id');
    }

    /** Whether the typed grade and the grade read from the scan disagree (both known). */
    public function mismatch(): bool
    {
        return $this->grade && $this->extracted_grade && strtoupper($this->grade) !== strtoupper($this->extracted_grade);
    }
}
