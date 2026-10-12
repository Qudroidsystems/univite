<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone signed in to the mobile app, with its push token (Firebase Cloud Messaging). */
class DeviceToken extends Model
{
    public const PLATFORMS = ['android', 'ios', 'web'];

    protected $fillable = ['user_id', 'token', 'token_hash', 'platform', 'device_name', 'app_version', 'access_token_id', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    protected $hidden = ['token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }
}
