<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentClearance extends Model
{
    public const STATUSES = ['pending' => 'Pending', 'cleared' => 'Cleared', 'rejected' => 'Rejected'];
    public const BADGES = ['pending' => 'bg-warning-subtle text-warning', 'cleared' => 'bg-success-subtle text-success', 'rejected' => 'bg-danger-subtle text-danger'];

    protected $fillable = ['student_id', 'clearance_item_id', 'status', 'note', 'student_note', 'decided_by', 'decided_at', 'resubmitted_at'];

    protected $casts = ['decided_at' => 'datetime', 'resubmitted_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ClearanceItem::class, 'clearance_item_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
