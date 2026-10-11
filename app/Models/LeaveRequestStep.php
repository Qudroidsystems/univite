<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One approval stage of an application and what happened at it. */
class LeaveRequestStep extends Model
{
    public const STATUSES = ['waiting' => 'Not reached yet', 'pending' => 'Waiting for a decision', 'approved' => 'Approved', 'rejected' => 'Not approved', 'returned' => 'Returned for correction', 'skipped' => 'Skipped'];

    protected $fillable = ['leave_request_id', 'step', 'label', 'approver', 'role_name', 'approver_id', 'status', 'acted_by', 'acted_at', 'comment', 'due_days', 'due_at', 'reminded_at', 'escalated_at'];

    protected $casts = ['acted_at' => 'datetime', 'due_at' => 'datetime', 'reminded_at' => 'datetime', 'escalated_at' => 'datetime'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
