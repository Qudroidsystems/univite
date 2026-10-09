<?php

namespace App\Services;

use App\Models\Setting;

/** Course-registration rules the university chooses. Stored as settings; these are the defaults. */
class RegistrationSettings
{
    public const DEFAULTS = [
        'registration.open'                => '1',      // students may register at all
        'registration.min_units'           => '15',
        'registration.max_units'           => '24',
        'registration.require_approval'    => '1',      // a course adviser / HOD approves each form
        'registration.fee_rule'            => 'none',   // none | first_instalment | full
        'registration.require_clearance'   => '1',      // new students must be cleared first
        'registration.force_carryovers'    => '1',      // failed courses must be registered again
        'registration.check_prerequisites' => '1',
        'registration.carryover_exempt'    => '1',      // carry-overs do not count against the maximum
    ];

    public const FEE_RULES = ['none' => 'Do not check fees', 'first_instalment' => 'First instalment (or full fee) paid', 'full' => 'Whole fee paid'];

    public static function get(string $name): string
    {
        $key = 'registration.' . $name;

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
            $out[substr($key, 13)] = self::get(substr($key, 13));
        }

        return $out;
    }
}
