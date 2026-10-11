<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A student's cumulative totals from the old records system, carried into the CGPA the portal works out. */
class LegacyBalance extends Model
{
    protected $fillable = ['student_id', 'tcp', 'tnu', 'units_passed', 'as_of_session_id', 'note', 'legacy_import_id'];

    protected $casts = ['tcp' => 'float', 'tnu' => 'integer', 'units_passed' => 'integer'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function asOf(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'as_of_session_id');
    }

    public function getCgpaAttribute(): float
    {
        return $this->tnu > 0 ? round($this->tcp / $this->tnu, 2) : 0.0;
    }
}
