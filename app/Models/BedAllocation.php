<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BedAllocation extends Model
{
    /** Statuses that hold a bed. */
    public const HOLDING = ['reserved', 'confirmed', 'checked_in'];

    public const STATUSES = ['reserved' => 'Reserved (awaiting payment)', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked in', 'checked_out' => 'Checked out',
        'released' => 'Released (not paid in time)', 'cancelled' => 'Cancelled'];

    public const BADGES = ['reserved' => 'bg-warning-subtle text-warning', 'confirmed' => 'bg-info-subtle text-info', 'checked_in' => 'bg-success-subtle text-success',
        'checked_out' => 'bg-secondary-subtle text-secondary', 'released' => 'bg-danger-subtle text-danger', 'cancelled' => 'bg-danger-subtle text-danger'];

    protected $fillable = ['student_id', 'academic_session_id', 'hostel_room_id', 'bed', 'status', 'application_id', 'invoice_id', 'reserved_until', 'confirmed_at',
        'checked_in_at', 'checked_in_by', 'checked_out_at', 'checked_out_by', 'ended_at', 'end_reason', 'allocated_by'];

    protected $casts = ['reserved_until' => 'datetime', 'confirmed_at' => 'datetime', 'checked_in_at' => 'datetime', 'checked_out_at' => 'datetime', 'ended_at' => 'datetime'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'hostel_room_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function scopeHolding(Builder $q): Builder
    {
        return $q->whereIn('status', self::HOLDING);
    }

    public function holdsBed(): bool
    {
        return in_array($this->status, self::HOLDING, true);
    }

    public function place(): string
    {
        return $this->room->hostel->name . ', ' . $this->room->label() . ', bed ' . $this->bed;
    }
}
