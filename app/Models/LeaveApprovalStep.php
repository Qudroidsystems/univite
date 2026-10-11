<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stage of a leave approval route. Steps with no leave type form the default route. */
class LeaveApprovalStep extends Model
{
    public const APPROVERS = ['supervisor' => 'The applicant\'s supervisor', 'hod' => 'Head of the applicant\'s department', 'dean' => 'Dean of the applicant\'s faculty', 'role' => 'Anyone with a role'];

    protected $fillable = ['leave_type_id', 'sort_order', 'label', 'approver', 'role_name', 'due_days', 'can_skip_if_missing'];

    protected $casts = ['can_skip_if_missing' => 'boolean'];

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
