<?php

namespace App\Services\Campus;

use App\Support\MobileAppSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Live updates through Pusher Channels, when the school has set it up under Settings → Mobile App. Without it nothing
 * is sent and the app checks for new messages itself. Uses Pusher's HTTP API directly (no extra package).
 */
class Realtime
{
    public function __construct(private MobileAppSettings $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->pusherReady();
    }

    /** Send an event to private channels (at most 100 per call, as Pusher allows). Failures are logged, never thrown. */
    public function trigger(array $channels, string $event, array $data): void
    {
        if (! $this->enabled() || ! $channels) {
            return;
        }
        $appId = $this->settings->get('pusher_app_id');
        $key = $this->settings->get('pusher_key');
        $secret = $this->settings->get('pusher_secret');
        $host = 'api-' . ($this->settings->get('pusher_cluster') ?: 'eu') . '.pusher.com';

        foreach (array_chunk(array_values(array_unique($channels)), 100) as $chunk) {
            $body = json_encode(['name' => $event, 'channels' => $chunk, 'data' => json_encode($data)]);
            $path = "/apps/{$appId}/events";
            $query = ['auth_key' => $key, 'auth_timestamp' => time(), 'auth_version' => '1.0', 'body_md5' => md5($body)];
            ksort($query);
            $qs = http_build_query($query);
            $query['auth_signature'] = hash_hmac('sha256', "POST\n{$path}\n{$qs}", $secret);
            try {
                Http::timeout(5)->withBody($body, 'application/json')->post("https://{$host}{$path}?" . http_build_query($query));
            } catch (\Throwable $e) {
                Log::warning('Pusher trigger failed: ' . $e->getMessage());
            }
        }
    }

    /** Signature that lets a phone subscribe to a private channel (POST /api/v1/realtime/auth). */
    public function authorize(string $socketId, string $channel): string
    {
        return $this->settings->get('pusher_key') . ':' . hash_hmac('sha256', "{$socketId}:{$channel}", (string) $this->settings->get('pusher_secret'));
    }
}
