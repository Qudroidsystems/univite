<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Applicant extends Model
{
    public const ENTRY_MODES = [
        'utme'         => 'UTME (100 level)',
        'direct_entry' => 'Direct Entry',
        'transfer'     => 'Transfer',
        'postgraduate' => 'Postgraduate',
    ];

    public const STATUSES = ['pending' => 'Pending', 'admitted' => 'Admitted', 'rejected' => 'Rejected'];

    /** Personal, origin and guardian details that are copied onto the student when admitted. */
    public const PROFILE_FIELDS = [
        'first_name', 'last_name', 'other_names', 'phone_number', 'gender', 'date_of_birth',
        'state_of_origin', 'lga', 'nationality', 'address',
        'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_address',
    ];

    protected $fillable = [
        'application_no', 'email', 'programme_id', 'academic_session_id', 'entry_mode', 'entry_level',
        'status', 'decided_at', ...self::PROFILE_FIELDS,
    ];

    protected $casts = ['date_of_birth' => 'date', 'decided_at' => 'datetime'];

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
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
