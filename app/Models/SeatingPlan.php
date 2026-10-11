<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Who sits where for every paper of an examination timetable. Students see their seats once it is published. */
class SeatingPlan extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'published' => 'Published to students', 'superseded' => 'Replaced'];
    public const BADGES = ['draft' => 'bg-secondary-subtle text-secondary', 'approved' => 'bg-info-subtle text-info', 'published' => 'bg-success-subtle text-success', 'superseded' => 'bg-light text-muted'];
    public const PATTERNS = [
        'side' => 'No two candidates of the same paper side by side',
        'side_front' => 'Not side by side, nor directly in front or behind',
        'diagonal' => 'Not side by side, in front, behind or diagonally',
    ];

    protected $fillable = ['timetable_id', 'status', 'rules', 'summary', 'generated_by', 'generated_at', 'approved_by', 'approved_at', 'published_by', 'published_at'];

    protected $casts = ['rules' => 'array', 'summary' => 'array', 'generated_at' => 'datetime', 'approved_at' => 'datetime', 'published_at' => 'datetime'];

    protected $attributes = ['status' => 'draft'];

    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(ExamSeat::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
