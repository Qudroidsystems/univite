<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccommodationApplication extends Model
{
    public const STATUSES = ['pending' => 'Waiting', 'allocated' => 'Given a bed', 'unsuccessful' => 'No bed available', 'withdrawn' => 'Withdrawn'];

    public const BADGES = ['pending' => 'bg-warning-subtle text-warning', 'allocated' => 'bg-success-subtle text-success', 'unsuccessful' => 'bg-danger-subtle text-danger', 'withdrawn' => 'bg-secondary-subtle text-secondary'];

    protected $fillable = ['student_id', 'academic_session_id', 'preferences', 'status', 'note'];

    protected $casts = ['preferences' => 'array'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }
}
