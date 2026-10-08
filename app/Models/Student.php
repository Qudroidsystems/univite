<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Student extends Model
{
    protected $fillable = [
        'user_id', 'applicant_id', 'matric_number', 'admission_batch',
        'needs_onboarding', 'offer_accepted_at', 'verified_at', 'verified_by', 'verification_note',
        'programme_id', 'academic_session_id', 'entry_mode', 'entry_level', 'current_level', 'status', 'photo',
        ...Applicant::PROFILE_FIELDS,
    ];

    protected $casts = ['date_of_birth' => 'date', 'needs_onboarding' => 'boolean', 'offer_accepted_at' => 'datetime', 'verified_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
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

    public function invoices(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Invoice::class, 'payable');
    }

    /** The login email lives on the user account. */
    public function getEmailAttribute(): ?string
    {
        return $this->user?->email;
    }

    public function getIdentifierAttribute(): ?string
    {
        return $this->matric_number;
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
