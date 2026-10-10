<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * How the portal sends email, set under Emails → Settings instead of the .env file. Left on "Server default", the
 * MAIL_* values in .env are used. The password is stored encrypted.
 */
class MailSettings
{
    public const MAILERS = ['' => 'Server default (.env)', 'smtp' => 'SMTP server', 'log' => 'Do not send (write to the log only)'];

    public static function get(string $key): ?string
    {
        $v = Setting::get("mail.{$key}");
        if ($key === 'password' && $v) {
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
        foreach (['mailer', 'host', 'port', 'encryption', 'username', 'from_address', 'from_name'] as $k) {
            if (array_key_exists($k, $values)) {
                Setting::put("mail.{$k}", $values[$k] !== null && $values[$k] !== '' ? (string) $values[$k] : null);
            }
        }
        if (! empty($values['password'])) {
            Setting::put('mail.password', Crypt::encryptString($values['password']));
        }
    }

    /** Apply the saved settings to Laravel's mail config (called on every request). */
    public static function apply(): void
    {
        $mailer = self::get('mailer');
        if ($mailer === 'smtp') {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => self::get('host'),
                'mail.mailers.smtp.port' => (int) (self::get('port') ?: 587),
                'mail.mailers.smtp.encryption' => self::get('encryption') ?: null,
                'mail.mailers.smtp.scheme' => self::get('encryption') === 'ssl' ? 'smtps' : null,
                'mail.mailers.smtp.username' => self::get('username'),
                'mail.mailers.smtp.password' => self::get('password'),
            ]);
        } elseif ($mailer === 'log') {
            config(['mail.default' => 'log']);
        }
        if ($from = self::get('from_address')) {
            config(['mail.from.address' => $from]);
        }
        config(['mail.from.name' => self::get('from_name') ?: Institution::get('name')]);
    }
}
