<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Academic staff on grade 12 and above with 10+ years of service get 36 days." Blank criteria match everyone. */
class LeaveEntitlement extends Model
{
    protected $fillable = ['leave_type_id', 'category', 'appointment_type', 'min_grade', 'max_grade', 'min_years', 'days'];

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function matches(?StaffProfile $p): bool
    {
        $grade = $p?->grade_level;
        $years = intdiv($p?->serviceMonths() ?? 0, 12);

        return (! $this->category || $this->category === $p?->category)
            && (! $this->appointment_type || $this->appointment_type === $p?->appointment_type)
            && ($this->min_grade === null || ($grade !== null && $grade >= $this->min_grade))
            && ($this->max_grade === null || ($grade !== null && $grade <= $this->max_grade))
            && $years >= (int) $this->min_years;
    }

    /** How specific a rule is: the most specific matching rule wins. */
    public function specificity(): int
    {
        return ($this->category ? 8 : 0) + ($this->appointment_type ? 4 : 0) + ($this->min_grade !== null || $this->max_grade !== null ? 2 : 0) + ($this->min_years ? 1 : 0);
    }
}
