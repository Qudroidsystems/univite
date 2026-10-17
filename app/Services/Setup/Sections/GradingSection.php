<?php

namespace App\Services\Setup\Sections;

use App\Data\Setup\RestorePlan;
use App\Models\AcademicSession;
use App\Models\DegreeClass;
use App\Models\GradeBand;
use App\Models\ResultKey;
use App\Services\Setup\RowSync;
use App\Services\Setup\SetupSection;

/** Key to Grade (grade bands), Key to Result and the classes of degree. */
class GradingSection implements SetupSection
{
    public function key(): string
    {
        return 'grading';
    }

    public function label(): string
    {
        return 'Grading keys';
    }

    public function about(): string
    {
        return 'Key to Grade (score bands, points, pass marks), Key to Result (GPA remarks) and the classes of degree.';
    }

    public function perSession(): bool
    {
        return false;
    }

    /** @return array<string, RowSync> */
    private function syncs(): array
    {
        return [
            'bands' => new RowSync(GradeBand::class, fn () => GradeBand::query(), (new GradeBand())->getFillable()),
            'keys' => new RowSync(ResultKey::class, fn () => ResultKey::query(), (new ResultKey())->getFillable()),
            'classes' => new RowSync(DegreeClass::class, fn () => DegreeClass::query(), (new DegreeClass())->getFillable()),
        ];
    }

    public function capture(?AcademicSession $session): array
    {
        return array_map(fn (RowSync $s) => $s->capture(), $this->syncs());
    }

    public function count(array $data): int
    {
        return array_sum(array_map('count', $data));
    }

    public function restore(array $data, ?AcademicSession $session, bool $apply): RestorePlan
    {
        $plan = new RestorePlan();
        foreach ($this->syncs() as $part => $sync) {
            $plan = $plan->plus($sync->restore($data[$part] ?? [], $apply));
        }

        return $plan;
    }
}
