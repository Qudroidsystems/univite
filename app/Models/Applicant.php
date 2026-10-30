<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Applicant extends Model
{
    public const STATUSES = ['draft' => 'Not submitted', 'pending' => 'Submitted', 'admitted' => 'Admitted', 'rejected' => 'Rejected'];

    /** Personal, origin and guardian details that are copied onto the student when admitted. */
    public const PROFILE_FIELDS = [
        'first_name', 'last_name', 'other_names', 'phone_number', 'gender', 'date_of_birth',
        'state_of_origin', 'lga', 'nationality', 'address',
        'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_address',
        'nin', 'religion', 'blood_group', 'genotype', 'marital_status', 'next_of_kin_email',
    ];

    protected $fillable = [
        'application_no', 'email', 'jamb_reg_no', 'jamb_score', 'password', 'submitted_at', 'admission_batch', 'programme_id', 'academic_session_id', 'entry_mode', 'entry_level',
        'status', 'decided_at', 'application_type_id', 'decision_message', 'decided_by', ...self::PROFILE_FIELDS,
    ];

    protected $hidden = ['password'];

    protected $casts = ['date_of_birth' => 'date', 'decided_at' => 'datetime', 'submitted_at' => 'datetime', 'password' => 'hashed'];

    public function applicationType(): BelongsTo
    {
        return $this->belongsTo(ApplicationType::class);
    }

    /** The staff member who admitted or rejected the application. */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function results(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApplicantResult::class)->orderBy('exam_type')->orderBy('subject');
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApplicantDocument::class);
    }

    public function invoices(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Invoice::class, 'payable');
    }

    /** What staff see in lists: the application number. */
    public function getIdentifierAttribute(): ?string
    {
        return $this->application_no;
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->last_name . ' ' . $this->first_name . ' ' . $this->other_names);
    }
}
