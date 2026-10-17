<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One student's computed semester result (see App\Services\Results\ResultComputation). */
class ComputedResult extends Model
{
    public const SEM_REMARKS = [
        'PASS' => 'Passed every course this semester',
        'REP' => 'Has courses to repeat',
        'NRI' => 'No registration information',
        'INC' => 'Incomplete: a registered course has no submitted score',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'present_tcp' => 'float', 'present_gpa' => 'float', 'previous_tcp' => 'float', 'previous_gpa' => 'float',
        'cumulative_tcp' => 'float', 'cgpa' => 'float', 'outstanding' => 'array', 'graduating' => 'boolean', 'computed_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function resultKey(): BelongsTo
    {
        return $this->belongsTo(ResultKey::class);
    }

    public function computedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'computed_by');
    }
}
