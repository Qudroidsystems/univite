<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A set of matric numbers waiting for approval (or approved / rejected). */
class MatricBatch extends Model
{
    public const STATUSES = ['pending' => 'Awaiting approval', 'approved' => 'Approved and published', 'rejected' => 'Rejected'];
    public const BADGES = ['pending' => 'bg-warning-subtle text-warning', 'approved' => 'bg-success-subtle text-success', 'rejected' => 'bg-secondary-subtle text-secondary'];

    protected $fillable = ['code', 'source', 'status', 'created_by', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['decided_at' => 'datetime'];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'provisional_batch', 'code');
    }

    /** Every number proposed in this batch, including ones since published or released. */
    public function proposals(): HasMany
    {
        return $this->hasMany(MatricEvent::class, 'batch', 'code')->where('action', 'proposed');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
