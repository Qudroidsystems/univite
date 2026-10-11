<?php

namespace App\Timetabling;

/**
 * Everything the solver needs, as plain arrays so it runs fast and can be changed for what-if runs without touching
 * the database.
 *
 * slots:   key => [key, group, day, date, start, end, minutes, pos, order]   (group = a weekday for lectures, a date for exams)
 * units:   id  => [id, item, course, code, title, size, length, minutes, venueType, facilities, staff, preferred, dept, cohorts, use, group]
 * venues:  id  => [id, code, name, cap, type, facilities, shared, building, dept, uses, blocked => [slotKey => reason], why]
 * conflicts: itemA => [itemB => students in common] (curriculum cohorts count as a clash too)
 * staffBusy: userId => [slotKey => reason]
 * protected: slotKey => label
 * fixed: unitId => [slots => [...], venues => [venueId => seats]] (placed by hand; never moved)
 */
class Problem
{
    public string $kind = 'lecture';
    public array $slots = [];
    public array $groups = [];
    public array $units = [];
    public array $venues = [];
    public array $conflicts = [];
    public array $staffBusy = [];
    public array $staffNames = [];
    public array $protected = [];
    public array $fixed = [];
    public array $weights = [];
    public array $cohortNames = [];
    public int $maxConsecutive = 3;
    public int $maxSplit = 4;
    public int $seed = 1;
    public int $maxSize = 1;

    public function isExam(): bool
    {
        return $this->kind === 'exam';
    }

    public function w(string $key): int
    {
        return (int) ($this->weights[$key] ?? 0);
    }

    public function clash(int $itemA, int $itemB): int
    {
        return $this->conflicts[$itemA][$itemB] ?? 0;
    }

    /** Index the slots by group once they are all added. */
    public function finalise(): void
    {
        $this->groups = [];
        $order = 0;
        foreach ($this->slots as $key => &$slot) {
            $slot['order'] = $order++;
            $this->groups[$slot['group']][] = $key;
        }
        unset($slot);
        foreach ($this->groups as $g => $keys) {
            foreach ($keys as $i => $k) {
                $this->slots[$k]['pos'] = $i;
            }
        }
        $this->maxSize = max(1, ...array_map(fn ($u) => $u['size'], $this->units ?: [['size' => 1]]));
    }

    /**
     * The runs of slots a unit could start at: `length` back-to-back periods on one day for a lecture, one sitting long
     * enough for an exam.
     *
     * @return array<int, array<int, string>>
     */
    public function starts(array $unit): array
    {
        $out = [];
        foreach ($this->groups as $keys) {
            $n = count($keys);
            for ($i = 0; $i < $n; $i++) {
                if ($this->isExam()) {
                    $out[] = [$keys[$i]];
                    continue;
                }
                if ($i + $unit['length'] > $n) {
                    break;
                }
                $run = [$keys[$i]];
                for ($j = 1; $j < $unit['length']; $j++) {
                    $prev = $this->slots[$keys[$i + $j - 1]];
                    $next = $this->slots[$keys[$i + $j]];
                    if ($prev['end'] !== $next['start']) {
                        $run = null;
                        break;
                    }
                    $run[] = $keys[$i + $j];
                }
                if ($run) {
                    $out[] = $run;
                }
            }
        }

        return $out;
    }

    /** Venues that could ever hold the unit (use, type, facilities), ignoring time. */
    public function suitableVenues(array $unit): array
    {
        return array_filter($this->venues, fn ($v) => in_array($unit['use'], $v['uses'], true)
            && (! $unit['venueType'] || $v['type'] === $unit['venueType'])
            && ! array_diff($unit['facilities'], $v['facilities']));
    }
}
