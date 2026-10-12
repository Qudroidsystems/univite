<?php

namespace App\Services\Api;

use App\Data\Api\LoginRequestData;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

/** Signing in from the mobile app with an email or matric number. Each sign-in gets its own token, which signing out revokes. */
class MobileAuth
{
    public function __construct(private Hasher $hasher, private DeviceRegistry $devices)
    {
    }

    public function find(string $login): ?User
    {
        if (str_contains($login, '@')) {
            return User::where('email', $login)->first();
        }
        $student = Student::where('matric_number', strtoupper($login))->orWhere('matric_number', $login)->first();

        return $student?->user ?? User::where('username', $login)->first();
    }

    public function login(LoginRequestData $data): NewAccessToken
    {
        $user = $this->find($data->login);
        if (! $user || ! $this->hasher->check($data->password, $user->password)) {
            throw ValidationException::withMessages(['login' => 'Those details do not match our records.']);
        }
        if ($user->is_disabled) {
            throw ValidationException::withMessages(['login' => 'This account has been disabled. Please contact the administrator.']);
        }

        $token = $user->createToken(mb_substr($data->deviceName, 0, 120), ['mobile']);
        if ($data->pushToken) {
            $this->devices->register($user, $data->pushToken, $data->platform, $data->deviceName, $data->appVersion, $token->accessToken->id);
        }
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $token;
    }
}
