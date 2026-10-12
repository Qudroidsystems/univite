<?php

namespace App\Services\Hr;

use App\Data\Hr\Retirement;
use App\Models\Setting;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Retirement: at a set age (a later age for the professorial cadre) or after a set number of years of service,
 * whichever comes first. The ages and years are settings because they differ between institutions and change in law.
 */
class RetirementRules
{
    public const DEFAULTS = ['retirement_age' => 65, 'retirement_age_professorial' => 70, 'max_service_years' => 35];

    public function setting(string $key): int
    {
        return (int) Setting::get("hr.{$key}", (string) self::DEFAULTS[$key]);
    }

    /** @param  array<string, int>  $values */
    public function save(array $values): void
    {
        foreach (array_keys(self::DEFAULTS) as $k) {
            if (isset($values[$k])) {
                Setting::put("hr.{$k}", (string) (int) $values[$k]);
            }
        }
    }

    public function for(StaffProfile $p): ?Retirement
    {
        $p->loadMissing('user');
        $options = [];
        if ($dob = $p->user?->date_of_birth) {
            $age = $this->setting($p->professorial ? 'retirement_age_professorial' : 'retirement_age');
            $options[] = new Retirement(CarbonImmutable::parse($dob)->addYears($age), "Reaches {$age}");
        }
        if ($p->first_appointment_date && ($years = $this->setting('max_service_years')) > 0) {
            $options[] = new Retirement(CarbonImmutable::parse($p->first_appointment_date)->addYears($years), "{$years} years of service");
        }

        return collect($options)->sortBy(fn (Retirement $r) => $r->date)->first();
    }

    /**
     * Staff still in service who retire within the given number of months (or already should have).
     *
     * @return Collection<int, array{profile: StaffProfile, retirement: Retirement}>
     */
    public function due(int $months, ?\Closure $limit = null): Collection
    {
        $until = CarbonImmutable::today()->addMonths($months);

        return StaffProfile::with('user', 'department', 'unit')->whereNull('exit_date')
            ->whereNotIn('employment_status', ['retired', 'resigned', 'dismissed', 'deceased'])
            ->when($limit, $limit)->get()
            ->map(fn ($p) => ['profile' => $p, 'retirement' => $this->for($p)])
            ->filter(fn ($r) => $r['retirement'] && $r['retirement']->date->lte($until))
            ->sortBy(fn ($r) => $r['retirement']->date)->values();
    }
}
