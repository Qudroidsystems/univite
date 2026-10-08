<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Works out which bills apply to a student. A bill with a blank target applies to everyone; filled
 * targets must match. When several bills exist for the same item (say a general fee and a higher foreign
 * fee), the most specific one wins, so a foreign-student bill replaces the general one.
 */
class BillMatcher
{
    public static function homeNationality(): string
    {
        return (string) Setting::get('institution.home_nationality', 'Nigerian');
    }

    public static function isHome(?string $nationality): bool
    {
        $n = strtolower(trim((string) $nationality));

        return $n === '' || $n === strtolower(static::homeNationality());
    }

    /**
     * @param int|null $semesterTypeId null = the session-wide fees; an id = the fees billed for that semester only
     * @return Collection<int, Bill>
     */
    public function forStudent(Student $student, AcademicSession $session, ?int $semesterTypeId = null, bool $includeOptional = false, ?int $onlyItemId = null, ?int $level = null): Collection
    {
        $student->loadMissing('programme.department');
        $programme = $student->programme;
        $type = $student->academic_session_id === $session->id ? 'fresh' : 'returning';
        $nationality = static::isHome($student->nationality) ? 'home' : 'foreign';

        $bills = Bill::with(['item', 'programme.studyCategory', 'studyCategory', 'semesterType'])
            ->where('academic_session_id', $session->id)
            ->where('is_active', true)
            ->whereIn('student_type', ['all', $type])
            ->whereIn('nationality', ['any', $nationality])
            ->where(fn ($q) => $q->whereNull('programme_id')->orWhere('programme_id', $student->programme_id))
            ->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $programme->department_id))
            ->where(fn ($q) => $q->whereNull('faculty_id')->orWhere('faculty_id', $programme->department?->faculty_id))
            ->where(fn ($q) => $q->whereNull('study_category_id')->orWhere('study_category_id', $programme->study_category_id))
            ->where(fn ($q) => $q->whereNull('level')->orWhere('level', $level ?? $student->current_level))
            ->when($semesterTypeId, fn ($q) => $q->where('semester_type_id', $semesterTypeId), fn ($q) => $q->whereNull('semester_type_id'))
            ->whereHas('item', fn ($q) => $q->where('is_active', true)->when(! $includeOptional && ! $onlyItemId, fn ($q) => $q->where('is_compulsory', true)))
            ->when($onlyItemId, fn ($q, $id) => $q->where('bill_item_id', $id),
                // the acceptance fee is billed separately, when the student accepts the offer
                fn ($q) => $q->when(ApplicationSettings::acceptanceItemId(), fn ($q, $id) => $q->where('bill_item_id', '!=', $id)))
            ->get();

        return $this->mostSpecificPerItem($bills)->sortBy(fn ($b) => $b->item->name)->values();
    }

    /** Keep one bill per bill item: the most specific; ties go to the newest. */
    public function mostSpecificPerItem(Collection $bills): Collection
    {
        return $bills->groupBy('bill_item_id')
            ->map(fn ($group) => $group->sortByDesc(fn ($b) => [$b->specificity(), $b->id])->first())
            ->values();
    }

    /**
     * Instalment amounts for a set of bills: each bill splits by its own percentages (one instalment = 100%),
     * the instalments are added together by number, and the last one absorbs rounding.
     *
     * @return array<int, float>
     */
    public function plan(Collection $bills): array
    {
        $count = max(1, (int) $bills->max('installments'));
        $parts = array_fill(0, $count, 0.0);

        foreach ($bills as $bill) {
            $percents = $this->percents($bill);
            $given = 0.0;
            foreach ($percents as $i => $pct) {
                $share = $i === count($percents) - 1 ? round((float) $bill->amount - $given, 2) : round((float) $bill->amount * $pct / 100, 2);
                $given += $share;
                $parts[$i] += $share;
            }
        }

        return array_map(fn ($v) => round($v, 2), $parts);
    }

    /** A bill's percentages, always summing to 100 and as long as its instalment count. */
    public function percents(Bill $bill): array
    {
        $n = max(1, min(3, (int) $bill->installments));
        $p = array_map('floatval', array_values($bill->installment_percents ?? []));

        if ($n === 1 || count($p) !== $n || abs(array_sum($p) - 100) > 0.01) {
            return $n === 1 ? [100.0] : array_fill(0, $n, round(100 / $n, 2));
        }

        return $p;
    }

    /** The payment window common to a set of bills: opens when the last one opens, closes when the first one closes. */
    public function window(Collection $bills): array
    {
        return [$bills->pluck('opens_at')->filter()->max(), $bills->pluck('closes_at')->filter()->min()];
    }
}
