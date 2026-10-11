<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A staff member's leave application, from submission through approval to resumption. */
class LeaveRequest extends Model
{
    public const STATUSES = ['pending' => 'Awaiting approval', 'returned' => 'Returned for correction', 'approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'];
    public const BADGES = ['pending' => 'bg-warning-subtle text-warning', 'returned' => 'bg-info-subtle text-info', 'approved' => 'bg-success-subtle text-success', 'rejected' => 'bg-danger-subtle text-danger', 'cancelled' => 'bg-secondary-subtle text-secondary'];

    protected $fillable = [
        'reference', 'user_id', 'leave_type_id', 'year', 'start_date', 'end_date', 'days', 'expected_resumption', 'reason', 'address_during_leave', 'phone_during_leave',
        'acting_officer_id', 'acting_status', 'status', 'current_step', 'submitted_at', 'decided_at', 'decided_by', 'decision_note',
        'resumed_on', 'resumption_recorded_by', 'resumption_note', 'late_days', 'days_used', 'late_flagged_at', 'reminders', 'warnings',
    ];

    protected $casts = [
        'start_date' => 'date', 'end_date' => 'date', 'expected_resumption' => 'date', 'resumed_on' => 'date',
        'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'late_flagged_at' => 'datetime', 'reminders' => 'array', 'warnings' => 'array',
    ];

    protected $attributes = ['status' => 'pending', 'current_step' => 1];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function actingOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_officer_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LeaveRequestStep::class)->orderBy('step');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LeaveDocument::class);
    }

    public function currentStep(): ?LeaveRequestStep
    {
        return $this->steps->firstWhere('status', 'pending');
    }

    /** Where it stands today: upcoming, on leave, due back, resumed (or the approval status). */
    public function getPhaseAttribute(): string
    {
        if ($this->status !== 'approved') {
            return self::STATUSES[$this->status] ?? $this->status;
        }
        if ($this->resumed_on) {
            return $this->late_days ? "Resumed {$this->late_days} working day(s) late" : 'Resumed';
        }
        $today = now()->startOfDay();

        return match (true) {
            $today->lt($this->start_date) => 'Approved, starts in ' . (int) $today->diffInDays($this->start_date) . ' day(s)',
            $today->lt($this->expected_resumption) => 'On leave, back ' . $this->expected_resumption->format('j M'),
            default => 'Due back since ' . $this->expected_resumption->format('j M'),
        };
    }

    /** Days counted against the balance: what was approved, or less if the person came back early. */
    public function getDaysChargedAttribute(): int
    {
        return $this->status === 'approved' ? (int) ($this->days_used ?? $this->days) : 0;
    }
}
