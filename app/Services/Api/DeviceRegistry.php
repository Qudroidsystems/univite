<?php

namespace App\Services\Api;

use App\Models\DeviceToken;
use App\Models\User;

/** Phones signed in to the mobile app. A push token belongs to one person: signing in as someone else on the same phone moves it. */
class DeviceRegistry
{
    public function register(User $user, string $token, string $platform = 'android', ?string $deviceName = null, ?string $appVersion = null, ?int $accessTokenId = null): DeviceToken
    {
        return DeviceToken::updateOrCreate(['token_hash' => DeviceToken::hashOf($token)], [
            'user_id' => $user->id, 'token' => $token, 'platform' => in_array($platform, DeviceToken::PLATFORMS, true) ? $platform : 'android',
            'device_name' => $deviceName ? mb_substr($deviceName, 0, 120) : null, 'app_version' => $appVersion ? mb_substr($appVersion, 0, 40) : null,
            'access_token_id' => $accessTokenId, 'last_seen_at' => now(),
        ]);
    }

    public function forget(User $user, string $token): bool
    {
        return (bool) $user->deviceTokens()->where('token_hash', DeviceToken::hashOf($token))->delete();
    }

    /** On sign-out: the phones registered with that sign-in stop getting pushes. */
    public function forgetForAccessToken(User $user, int $accessTokenId): int
    {
        return $user->deviceTokens()->where('access_token_id', $accessTokenId)->delete();
    }
}
