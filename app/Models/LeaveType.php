<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of leave (annual, sick, maternity ...), with its days, documents, eligibility and approval route. */
class LeaveType extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'default_days', 'working_days', 'paid', 'needs_document', 'document_label', 'confidential', 'gender',
        'min_service_months', 'max_days_per_request', 'notice_days', 'max_carryover', 'needs_acting_officer', 'deducts_balance', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'working_days' => 'boolean', 'paid' => 'boolean', 'needs_document' => 'boolean', 'confidential' => 'boolean',
        'needs_acting_officer' => 'boolean', 'deducts_balance' => 'boolean', 'is_active' => 'boolean',
    ];

    public function entitlements(): HasMany
    {
        return $this->hasMany(LeaveEntitlement::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LeaveApprovalStep::class)->orderBy('sort_order');
    }
}
