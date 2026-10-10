<?php

namespace App\Services;

use App\Models\Setting;

/** Institutional rules about sessions that vary between universities. Each is a setting with a sensible default. */
class SessionSettings
{
    public const DEFAULTS = [
        'session.allow_multiple_active'  => '0',   // more than one session may be Active at once
        'session.require_semesters'      => '1',   // a session needs at least one semester before it can go Active
        'session.allow_reopen'           => '1',   // a Closed session may be reopened (with a reason)
        'session.late_registration'      => '0',   // students may register after the window closes
        'session.late_registration_days' => '14',  // ...for this many days
    ];

    public const LABELS = [
        'allow_multiple_active' => ['More than one session can be active at the same time', 'Off = activating a session needs the previous one closed first.'],
        'require_semesters'     => ['A session must have a semester before it can be activated', ''],
        'allow_reopen'          => ['Closed sessions may be reopened', 'A reason is always recorded.'],
        'late_registration'     => ['Allow late course registration after the window closes', 'Students can still register for the number of days below.'],
    ];

    public static function get(string $name): string
    {
        $key = 'session.' . $name;

        return Setting::get($key, self::DEFAULTS[$key]);
    }

    public static function on(string $name): bool
    {
        return self::get($name) === '1';
    }

    public static function int(string $name): int
    {
        return (int) self::get($name);
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $name = substr($key, 8);
            $out[$name] = self::get($name);
        }

        return $out;
    }
}
