<?php

namespace App\Services\Setup\Sections;

use App\Data\Setup\RestorePlan;
use App\Models\AcademicSession;
use App\Models\PeriodSetting;
use App\Services\RegistrationSettings;
use App\Services\Setup\RowSync;
use App\Services\Setup\SetupSection;
use App\Services\Setup\SettingSync;

/** Course registration settings: the university's defaults and, for a session, what it and its semesters set. */
class RegistrationSection implements SetupSection
{
    public function key(): string
    {
        return 'registration';
    }

    public function label(): string
    {
        return 'Registration settings';
    }

    public function about(): string
    {
        return 'Whether registration is open, approval, clearance and fee checks, late registration, and the session\'s and semesters\' own values.';
    }

    public function perSession(): bool
    {
        return false;
    }

    private function settings(): SettingSync
    {
        return new SettingSync(array_merge(array_keys(RegistrationSettings::DEFAULTS), ['session.late_registration', 'session.late_registration_days']));
    }

    private function periods(?AcademicSession $session): ?RowSync
    {
        return $session ? new RowSync(PeriodSetting::class, fn () => PeriodSetting::where('academic_session_id', $session->id)->where('group', 'registration'),
            ['academic_session_id', 'semester_type_id', 'group', 'values', 'updated_by']) : null;
    }

    public function capture(?AcademicSession $session): array
    {
        return ['settings' => $this->settings()->capture(), 'periods' => $this->periods($session)?->capture() ?? []];
    }

    public function count(array $data): int
    {
        return count(array_filter($data['settings'] ?? [], fn ($v) => $v !== null)) + count($data['periods'] ?? []);
    }

    public function restore(array $data, ?AcademicSession $session, bool $apply): RestorePlan
    {
        $plan = $this->settings()->restore($data['settings'] ?? [], $apply);

        return ($periods = $this->periods($session)) ? $plan->plus($periods->restore($data['periods'] ?? [], $apply)) : $plan;
    }
}
