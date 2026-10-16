<?php

namespace App\Data\Results;

use App\Models\AcademicSession;
use App\Models\Programme;
use App\Models\SemesterType;
use Illuminate\Support\Collection;

/** The master sheet (broadsheet) of one programme and level for one semester, as Senate considers it. */
final readonly class MasterSheet
{
    public const BUCKETS = ['pass' => 'Passed all courses', 'carryover' => 'With carry-overs', 'probation' => 'On probation', 'withdraw' => 'Advised to withdraw', 'incomplete' => 'Results incomplete'];

    /**
     * @param  Collection<int, MasterSheetCourse>  $courses
     * @param  Collection<int, MasterSheetRow>  $rows
     */
    public function __construct(
        public Programme $programme,
        public int $level,
        public AcademicSession $session,
        public SemesterType $semesterType,
        public Collection $courses,
        public Collection $rows,
        public bool $provisional,
    ) {
    }

    public function title(): string
    {
        return "{$this->programme->name} · {$this->programme->levelLabel($this->level)} · {$this->session->name} {$this->semesterType->name}";
    }

    /** @return array<string, int> bucket => students */
    public function counts(): array
    {
        return collect(self::BUCKETS)->map(fn ($l, $k) => $this->rows->where('bucket', $k)->count())->all();
    }

    /** True when every course has been approved (or published), so nothing on the sheet can still change in the chain. */
    public function allApproved(): bool
    {
        return $this->courses->isNotEmpty() && $this->courses->every(fn (MasterSheetCourse $c) => $c->isFinal());
    }
}
