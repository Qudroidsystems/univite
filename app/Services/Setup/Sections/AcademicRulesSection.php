<?php

namespace App\Services\Setup\Sections;

use App\Data\Setup\RestorePlan;
use App\Models\AcademicRule;
use App\Models\AcademicSession;
use App\Services\Setup\RowSync;
use App\Services\Setup\SetupSection;
use App\Services\Setup\SettingSync;
use App\Support\AcademicRules;

/** The university's academic rules and every faculty, department and programme override. */
class AcademicRulesSection implements SetupSection
{
    public function key(): string
    {
        return 'rules';
    }

    public function label(): string
    {
        return 'Academic rules';
    }

    public function about(): string
    {
        return 'Credit loads, carry-over, probation and graduation rules: the university\'s values and every faculty, department and programme override.';
    }

    public function perSession(): bool
    {
        return false;
    }

    private function settings(): SettingSync
    {
        return new SettingSync(array_values(array_unique(array_filter(array_map(fn ($d) => $d[4] ?? null, AcademicRules::DEFS)))));
    }

    private function overrides(): RowSync
    {
        return new RowSync(AcademicRule::class, fn () => AcademicRule::query(), ['scope_type', 'scope_id', 'level', 'semester_type_id', 'rules', 'updated_by']);
    }

    public function capture(?AcademicSession $session): array
    {
        return ['settings' => $this->settings()->capture(), 'overrides' => $this->overrides()->capture()];
    }

    public function count(array $data): int
    {
        return count(array_filter($data['settings'] ?? [], fn ($v) => $v !== null)) + count($data['overrides'] ?? []);
    }

    public function restore(array $data, ?AcademicSession $session, bool $apply): RestorePlan
    {
        return $this->settings()->restore($data['settings'] ?? [], $apply)->plus($this->overrides()->restore($data['overrides'] ?? [], $apply));
    }
}
