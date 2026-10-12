<?php

namespace App\Data\Api;

/** What the mobile app sends to sign in: an email or matric number, the password, and optionally the phone's push token. */
final readonly class LoginRequestData
{
    public function __construct(
        public string $login, public string $password, public string $deviceName,
        public ?string $pushToken = null, public string $platform = 'android', public ?string $appVersion = null,
    ) {
    }

    public static function fromArray(array $d): self
    {
        return new self(trim($d['login']), $d['password'], $d['device_name'] ?? 'Mobile app', $d['push_token'] ?? null, $d['platform'] ?? 'android', $d['app_version'] ?? null);
    }
}
