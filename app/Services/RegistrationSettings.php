<?php

namespace App\Services;

use App\Data\Registration\RegistrationContext;
use App\Models\AcademicSession;
use App\Models\PeriodSetting;
use App\Models\SemesterType;
use App\Models\Setting;

/**
 * Course-registration rules the university chooses. Stored as settings; these are the defaults. Some of them
 * (PERIOD) a session can set for itself, and a semester of that session for itself again: the most specific wins.
 */
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

    /**
     * What a session or semester may set for itself: [label, help, kind]. Late registration lives with the session
     * rules (SessionSettings) as the university's default.
     */
    public const PERIOD = [
        'open'                   => ['Registration is open', 'Off closes course registration for everyone in this period.', 'bool'],
        'require_approval'       => ['An adviser/HOD must approve each form', 'Off = a submitted form is approved straight away.', 'bool'],
        'require_clearance'      => ['New students must be cleared first', 'Students still in admission clearance cannot register.', 'bool'],
        'fee_rule'               => ['Check school fees before registering', 'Uses the student\'s fees invoice for the session and semester.', 'fee'],
        'late_registration'      => ['Allow late registration after the window closes', 'Students can still register for the number of days below.', 'bool'],
        'late_registration_days' => ['Days of late registration', '', 'int'],
    ];

    public static function get(string $name, ?RegistrationContext $ctx = null): string
    {
        if ($ctx && array_key_exists($name, self::PERIOD)) {
            return self::period($ctx->session->id, $ctx->type->id)['values'][$name];
        }

        return self::base($name);
    }

    public static function on(string $name, ?RegistrationContext $ctx = null): bool
    {
        return self::get($name, $ctx) === '1';
    }

    public static function int(string $name, ?RegistrationContext $ctx = null): int
    {
        return (int) self::get($name, $ctx);
    }

    /** The university's own value, ignoring any session. */
    public static function base(string $name): string
    {
        if (str_starts_with($name, 'late_registration')) {
            return SessionSettings::get($name);
        }
        $key = 'registration.' . $name;

        return Setting::get($key, self::DEFAULTS[$key]);
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $out[substr($key, 13)] = self::get(substr($key, 13));
        }

        return $out;
    }

    /**
     * The values in force for a session (and optionally one semester of it), and where each comes from.
     *
     * @return array{values: array<string, string>, from: array<string, string>, own: array<string, string>}
     */
    public static function period(int $sessionId, ?int $semesterTypeId = null): array
    {
        $rows = PeriodSetting::where('academic_session_id', $sessionId)->where('group', 'registration')
            ->where(fn ($q) => $q->whereNull('semester_type_id')->when($semesterTypeId, fn ($w) => $w->orWhere('semester_type_id', $semesterTypeId)))
            ->get();
        $session = $rows->firstWhere('semester_type_id', null);
        $semester = $semesterTypeId ? $rows->firstWhere('semester_type_id', $semesterTypeId) : null;
        $sessionName = $session || $semester ? AcademicSession::whereKey($sessionId)->value('name') : '';
        $semesterName = $semester ? SemesterType::whereKey($semesterTypeId)->value('name') : '';

        $values = $from = [];
        foreach (array_keys(self::PERIOD) as $name) {
            if ($semester && array_key_exists($name, $semester->values ?? [])) {
                [$values[$name], $from[$name]] = [(string) $semester->values[$name], "{$sessionName} · {$semesterName}"];
            } elseif ($session && array_key_exists($name, $session->values ?? [])) {
                [$values[$name], $from[$name]] = [(string) $session->values[$name], $sessionName];
            } else {
                [$values[$name], $from[$name]] = [self::base($name), 'University default'];
            }
        }

        return ['values' => $values, 'from' => $from, 'own' => array_map('strval', ($semesterTypeId ? $semester : $session)?->values ?? [])];
    }
}
