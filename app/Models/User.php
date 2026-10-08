<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name', 'email', 'username', 'phone_number', 'gender', 'date_of_birth',
        'avatar', 'password', 'must_change_password', 'is_disabled', 'last_login_at',
    ];

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

    public function student(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Student::class);
    }
}
