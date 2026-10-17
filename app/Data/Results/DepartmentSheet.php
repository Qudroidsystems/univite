<?php

namespace App\Data\Results;

use App\Models\GradeBand;
use App\Models\ResultKey;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** The department master marks sheet: sections (a programme, graduating or not) of student rows, and the keys. */
final readonly class DepartmentSheet
{
    /**
     * @param  Collection<int, Collection<int, DepartmentSheetRow>>  $sections
     * @param  Collection<int, GradeBand>  $gradeKey
     * @param  Collection<int, ResultKey>  $resultKey
     */
    public function __construct(
        public ComputeQuery $query,
        public Collection $sections,
        public Collection $gradeKey,
        public Collection $resultKey,
        public ?CarbonInterface $computedAt,
    ) {
    }

    /** @return array<string, int> SEM RMK => students, e.g. PASS => 40 */
    public function semRemarks(): array
    {
        return $this->sections->flatten(1)->countBy(fn (DepartmentSheetRow $r) => $r->computed->sem_remark)->all();
    }

    /** @return array<string, int> CUM RMK => students */
    public function cumRemarks(): array
    {
        return $this->sections->flatten(1)->countBy(fn (DepartmentSheetRow $r) => $r->computed->cum_remark ?? '—')->all();
    }

    public function count(): int
    {
        return $this->sections->sum(fn ($s) => $s->count());
    }
}
