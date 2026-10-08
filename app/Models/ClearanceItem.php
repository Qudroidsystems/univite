<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClearanceItem extends Model
{
    /** Checks the system can run by itself: code => what it checks. */
    public const RULES = [
        'offer_accepted'      => 'The student accepted their admission offer',
        'acceptance_fee_paid' => 'The acceptance fee is paid (passes if none is set)',
        'biodata_complete'    => 'Personal and guardian details are complete',
        'documents_uploaded'  => 'All required documents are uploaded',
        'verified'            => 'Physical verification was done',
        'fees_paid'           => 'The school fees invoice for the entry session is paid',
    ];

    protected $fillable = ['name', 'code', 'description', 'mode', 'auto_rule', 'office_role', 'is_required', 'is_active', 'sort_order'];

    protected $casts = ['is_required' => 'boolean', 'is_active' => 'boolean'];

    public function clearances(): HasMany
    {
        return $this->hasMany(StudentClearance::class);
    }

    public function isAuto(): bool
    {
        return $this->mode === 'auto' && $this->auto_rule !== null;
    }
}
