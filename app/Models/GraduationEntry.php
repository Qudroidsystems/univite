<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraduationEntry extends Model
{
    protected $fillable = ['graduation_list_id', 'student_id', 'cgpa', 'units_passed', 'degree_class', 'is_eligible', 'problems', 'is_excluded', 'exclusion_note'];

    protected $casts = ['cgpa' => 'float', 'is_eligible' => 'boolean', 'is_excluded' => 'boolean', 'problems' => 'array'];

    public function list(): BelongsTo
    {
        return $this->belongsTo(GraduationList::class, 'graduation_list_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** On the list to graduate when the list is approved. */
    public function graduates(): bool
    {
        return $this->is_eligible && ! $this->is_excluded;
    }
}
