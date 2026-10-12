<?php

namespace App\Messaging;

use App\Data\Push\PushMessage;
use App\Data\Push\PushResult;
use App\Models\DeviceToken;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends a push notification to one phone through Firebase Cloud Messaging (HTTP v1), set up under Messaging settings
 * with the Firebase project id and a service account key (stored encrypted). Until then pushes are only written to the
 * log. A token Firebase no longer knows (the app was removed) comes back as "invalid" so the caller can forget it.
 */
class PushGateway
{
    public const PROVIDERS = ['log' => 'Not set up (write to the log only)', 'fcm' => 'Firebase Cloud Messaging'];

    public function __construct(private Http $http, private Cache $cache)
    {
    }

    public function configured(): bool
    {
        return Gateway::provider('push') === 'fcm' && Gateway::get('fcm_project_id') && $this->serviceAccount();
    }

    public function send(DeviceToken $device, PushMessage $message): PushResult
    {
        if (! $this->configured()) {
            Log::info('[push:log] to device ' . $device->id . ': ' . $message->title . ' - ' . $message->body);

            return PushResult::logged();
        }

        try {
            $res = $this->http->withToken($this->accessToken())->timeout(15)
                ->post('https://fcm.googleapis.com/v1/projects/' . rawurlencode((string) Gateway::get('fcm_project_id')) . '/messages:send', [
                    'message' => [
                        'token' => $device->token,
                        'notification' => ['title' => $message->title, 'body' => $message->body],
                        'data' => array_map('strval', $message->data),
                        'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'portal', 'sound' => 'default']],
                        'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                    ],
                ]);
        } catch (\Throwable $e) {
            return PushResult::failed($e->getMessage());
        }

        if ($res->successful()) {
            return new PushResult('sent', (string) $res->json('name'));
        }
        $code = (string) ($res->json('error.details.0.errorCode') ?? $res->json('error.status'));
        if ($res->status() === 404 || in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            return PushResult::invalid($code ?: 'UNREGISTERED');
        }

        return PushResult::failed($code . ' ' . mb_substr((string) $res->json('error.message'), 0, 200));
    }

    /** @return array{client_email: string, private_key: string, token_uri?: string}|null */
    private function serviceAccount(): ?array
    {
        $json = json_decode((string) Gateway::get('fcm_service_account'), true);

        return is_array($json) && ! empty($json['client_email']) && ! empty($json['private_key']) ? $json : null;
    }

    /** OAuth access token for the service account, kept for 50 minutes (Google issues them for an hour). */
    private function accessToken(): string
    {
        $sa = $this->serviceAccount() ?? throw new RuntimeException('The Firebase service account key is missing.');

        return $this->cache->remember('push.fcm.token.' . md5($sa['client_email']), 3000, function () use ($sa) {
            $uri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
                'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600,
            ]));
            if (! openssl_sign($unsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('The Firebase service account key could not be read.');
            }
            $res = $this->http->asForm()->timeout(15)->post($uri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . $b64($signature),
            ]);
            if (! $res->successful() || ! $res->json('access_token')) {
                throw new RuntimeException('Firebase refused the service account key: ' . mb_substr($res->body(), 0, 200));
            }

            return (string) $res->json('access_token');
        });
    }
}
