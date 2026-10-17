<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name', 'email', 'username', 'phone_number', 'whatsapp_number', 'notify_sms', 'notify_whatsapp', 'notify_push', 'gender', 'date_of_birth',
        'avatar', 'password', 'must_change_password', 'is_disabled', 'last_login_at', 'share_lodging', 'lodging_note', 'bio', 'last_seen_at',
    ];

    /** Who may see where a student lives (set by the student in the app). */
    public const SHARE_LODGING = ['nobody' => 'Nobody', 'mates' => 'My department and course mates', 'everyone' => 'Everyone in the school'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'password'             => 'hashed',
            'date_of_birth'        => 'date',
            'must_change_password' => 'boolean',
            'is_disabled'          => 'boolean',
            'last_login_at'        => 'datetime',
            'notify_sms'           => 'boolean',
            'notify_whatsapp'      => 'boolean',
            'notify_push'          => 'boolean',
            'last_seen_at'         => 'datetime',
        ];
    }

    public function getInitialsAttribute(): string
    {
        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter()->take(2)
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
            ->implode('');
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return asset('storage/avatars/' . $this->avatar);
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&color=7F9CF5&background=EBF4FF';
    }

    public function courseAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CourseAssignment::class);
    }

    /** The courses this person enters scores for: those defined for them, when they hold "Enter results for assigned courses". */
    public function scoringAssignments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->courseAssignments()->when(! $this->can(\App\Services\Access\LecturerRole::PERMISSION), fn ($q) => $q->whereRaw('1 = 0'));
    }

    public function staffProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function deviceTokens(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function student(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Student::class);
    }

    /** The department a student studies in or a member of staff works in. */
    public function departmentId(): ?int
    {
        if ($this->student) {
            return $this->student->programme?->department_id;
        }

        return $this->staffProfile?->department_id;
    }

    /**
     * The picture shown beside the person in the app: their own profile picture if they set one, otherwise a student's
     * official photo, otherwise null (the app shows their initials). The official photo (ID card) is never changed here.
     */
    public function photoUrl(): ?string
    {
        if ($this->avatar) {
            return asset('storage/avatars/' . $this->avatar);
        }

        return $this->student?->photo_url;
    }

    public function chatMemberships(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatMember::class);
    }
}
