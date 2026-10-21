<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One generation of a timetable: a candidate the committee can compare with others, then apply. */
class TimetableRun extends Model
{
    public const STATUSES = ['queued' => 'Waiting to run', 'running' => 'Running', 'done' => 'Ready to compare', 'failed' => 'Failed', 'applied' => 'In use'];

    public const BADGES = ['queued' => 'bg-secondary-subtle text-secondary', 'running' => 'bg-info-subtle text-info', 'done' => 'bg-primary-subtle text-primary',
        'failed' => 'bg-danger-subtle text-danger', 'applied' => 'bg-success-subtle text-success'];

    protected $fillable = ['code', 'timetable_id', 'batch', 'label', 'algorithm', 'seed', 'scope', 'config', 'status', 'assignment', 'summary', 'stats', 'unplaced',
        'duration_ms', 'error', 'created_by', 'started_at', 'finished_at', 'applied_at', 'applied_by'];

    protected $casts = ['scope' => 'array', 'config' => 'array', 'assignment' => 'array', 'summary' => 'array', 'stats' => 'array', 'unplaced' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'applied_at' => 'datetime'];

    protected $attributes = ['status' => 'queued'];

    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'applied', 'failed'], true);
    }

    public function algorithmLabel(): string
    {
        return \App\Timetabling\Solver::ALGORITHMS[$this->algorithm][0] ?? $this->algorithm;
    }
}
