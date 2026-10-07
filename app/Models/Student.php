<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Student extends Model
{
    public const STATUSES = [
        'active'    => 'Active',
        'deferred'  => 'Deferred',
        'suspended' => 'Suspended',
        'withdrawn' => 'Withdrawn',
        'graduated' => 'Graduated',
    ];

    public const STATUS_BADGES = [
        'active' => 'bg-success-subtle text-success', 'deferred' => 'bg-info-subtle text-info',
        'suspended' => 'bg-warning-subtle text-warning', 'withdrawn' => 'bg-secondary-subtle text-secondary',
        'graduated' => 'bg-primary-subtle text-primary',
    ];

    protected $fillable = [
        'user_id', 'applicant_id', 'matric_number',
        'programme_id', 'academic_session_id', 'entry_mode', 'entry_level', 'current_level', 'status', 'photo',
        ...Applicant::PROFILE_FIELDS,
    ];

    protected $casts = ['date_of_birth' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function entrySession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    /** The login email lives on the user account. */
    public function getEmailAttribute(): ?string
    {
        return $this->user?->email;
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->last_name . ' ' . $this->first_name . ' ' . $this->other_names);
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo ? Storage::disk('public')->url($this->photo) : null;
    }

    public function getInitialsAttribute(): string
    {
        return strtoupper(mb_substr($this->first_name, 0, 1) . mb_substr($this->last_name, 0, 1));
    }
}
