<?php

namespace App\Messaging;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends one SMS or WhatsApp message through the provider chosen under Messaging settings. Until a provider is set up
 * the message is only written to the log, so nothing is lost and nothing is sent by accident. Credentials are stored
 * encrypted. Providers: Twilio (SMS and WhatsApp), Termii (SMS and WhatsApp) and a generic webhook for any other.
 */
class Gateway
{
    public const PROVIDERS = ['log' => 'Not set up (write to the log only)', 'twilio' => 'Twilio', 'termii' => 'Termii', 'webhook' => 'Other provider (webhook)'];
    public const SECRETS = ['twilio_token', 'termii_key', 'webhook_token', 'fcm_service_account'];
    public const KEYS = ['sms_provider', 'whatsapp_provider', 'country_code', 'twilio_sid', 'twilio_from', 'twilio_whatsapp_from', 'termii_sender', 'webhook_url', 'twilio_token', 'termii_key', 'webhook_token', 'push_provider', 'fcm_project_id', 'fcm_service_account'];

    public static function get(string $key): ?string
    {
        $v = Setting::get("messaging.{$key}");
        if ($v && in_array($key, self::SECRETS, true)) {
            try {
                return Crypt::decryptString($v);
            } catch (\Throwable) {
                return null;
            }
        }

        return $v;
    }

    public static function put(array $values): void
    {
        foreach (self::KEYS as $k) {
            if (! array_key_exists($k, $values)) {
                continue;
            }
            if (in_array($k, self::SECRETS, true)) {
                if (! empty($values[$k])) {
                    Setting::put("messaging.{$k}", Crypt::encryptString($values[$k]));
                }
                continue;
            }
            Setting::put("messaging.{$k}", $values[$k] !== null && $values[$k] !== '' ? (string) $values[$k] : null);
        }
    }

    public static function provider(string $channel): string
    {
        return self::get($channel . '_provider') ?: 'log';
    }

    /** International format without the plus: 08031234567 -> 2348031234567. */
    public static function normalise(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);
        if ($digits === '' || strlen($digits) < 7) {
            return null;
        }
        $cc = self::get('country_code') ?: '234';
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            return $cc . substr($digits, 1);
        }

        return $digits;
    }

    /** @return array{0: string, 1: ?string, 2: ?string} [status sent|logged|failed, provider reference, error] */
    public function send(string $channel, string $to, string $body): array
    {
        $provider = self::provider($channel);
        try {
            return match ($provider) {
                'twilio' => $this->twilio($channel, $to, $body),
                'termii' => $this->termii($channel, $to, $body),
                'webhook' => $this->webhook($channel, $to, $body),
                default => $this->log($channel, $to, $body),
            };
        } catch (\Throwable $e) {
            return ['failed', null, mb_substr($e->getMessage(), 0, 480)];
        }
    }

    private function log(string $channel, string $to, string $body): array
    {
        Log::info("[{$channel} to +{$to}] {$body}");

        return ['logged', null, null];
    }

    private function twilio(string $channel, string $to, string $body): array
    {
        $sid = self::get('twilio_sid');
        $from = $channel === 'whatsapp' ? 'whatsapp:' . self::get('twilio_whatsapp_from') : self::get('twilio_from');
        $r = Http::asForm()->withBasicAuth((string) $sid, (string) self::get('twilio_token'))->timeout(20)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => $from, 'To' => ($channel === 'whatsapp' ? 'whatsapp:+' : '+') . $to, 'Body' => $body,
            ]);

        return $r->successful() ? ['sent', $r->json('sid'), null] : ['failed', null, 'Twilio: ' . ($r->json('message') ?? $r->status())];
    }

    private function termii(string $channel, string $to, string $body): array
    {
        $r = Http::timeout(20)->post('https://api.ng.termii.com/api/sms/send', [
            'api_key' => self::get('termii_key'), 'to' => $to, 'from' => self::get('termii_sender'), 'sms' => $body,
            'type' => 'plain', 'channel' => $channel === 'whatsapp' ? 'whatsapp' : 'generic',
        ]);

        return $r->successful() ? ['sent', (string) ($r->json('message_id') ?? ''), null] : ['failed', null, 'Termii: ' . ($r->json('message') ?? $r->status())];
    }

    private function webhook(string $channel, string $to, string $body): array
    {
        $r = Http::timeout(20)->withToken((string) self::get('webhook_token'))->post((string) self::get('webhook_url'), ['channel' => $channel, 'to' => $to, 'body' => $body]);

        return $r->successful() ? ['sent', (string) ($r->json('id') ?? ''), null] : ['failed', null, 'Webhook: HTTP ' . $r->status()];
    }
}
