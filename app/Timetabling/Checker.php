<?php

namespace App\Timetabling;

/**
 * Checks a complete placement against every hard rule, independently of the solver, so a timetable changed by hand
 * (or one generated before a venue closed or someone went on leave) is caught before approval and publication.
 */
class Checker
{
    public function __construct(private Problem $p)
    {
    }

    /** @return array<int, array{rule: string, units: array<int, string>, message: string}> */
    public function check(array $assign): array
    {
        $p = $this->p;
        $out = [];
        $atSlot = [];
        $venueAt = [];
        $staffAt = [];

        foreach ($assign as $id => $a) {
            $u = $p->units[$id] ?? null;
            if (! $u) {
                continue;
            }
            $slots = $a['slots'];
            if (! $slots || array_diff($slots, array_keys($p->slots))) {
                $out[] = $this->v('time', [$id], "{$u['code']} is at a time that is no longer in this timetable's periods.");
                continue;
            }
            $group = $p->slots[$slots[0]]['group'];
            if (count($slots) !== $u['length']) {
                $out[] = $this->v('duration', [$id], "{$u['code']} needs {$u['length']} period(s) but is given " . count($slots) . '.');
            }
            foreach ($slots as $i => $key) {
                $slot = $p->slots[$key];
                if ($slot['group'] !== $group || ($i > 0 && $p->slots[$slots[$i - 1]]['end'] !== $slot['start'])) {
                    $out[] = $this->v('duration', [$id], "{$u['code']}'s periods are not back to back.");
                }
                if (isset($p->protected[$key])) {
                    $out[] = $this->v('protected', [$id], "{$u['code']} is in a protected period ({$p->protected[$key]}).");
                }
                if ($p->isExam() && $u['minutes'] > $slot['minutes']) {
                    $out[] = $this->v('duration', [$id], "{$u['code']} lasts {$u['minutes']} minutes but its sitting is {$slot['minutes']}.");
                }
                foreach ($u['staff'] as $s) {
                    if (isset($p->staffBusy[$s][$key])) {
                        $out[] = $this->v('staff', [$id], ($p->staffNames[$s] ?? 'A lecturer') . " teaches {$u['code']} at {$this->when($key)} but is " . $p->staffBusy[$s][$key] . '.');
                    }
                    $staffAt[$s][$key][] = $id;
                }
                $atSlot[$key][] = $id;
                foreach ($a['venues'] as $vid => $seats) {
                    $venueAt[$vid][$key][$id] = $seats;
                }
            }
            $seated = array_sum($a['venues']);
            if ($u['size'] > 0 && $seated < $u['size']) {
                $out[] = $this->v('capacity', [$id], "Only {$seated} of {$u['size']} " . ($p->isExam() ? 'candidates' : 'students') . " of {$u['code']} have a place.");
            }
            foreach ($a['venues'] as $vid => $seats) {
                $v = $p->venues[$vid] ?? null;
                if (! $v) {
                    $out[] = $this->v('venue', [$id], "{$u['code']} is in a venue that is retired, has no seats set, or is not available to this timetable.");
                    continue;
                }
                if (! in_array($u['use'], $v['uses'], true) || ($u['venueType'] && $v['type'] !== $u['venueType']) || array_diff($u['facilities'], $v['facilities'])) {
                    $out[] = $this->v('venue', [$id], "{$v['name']} is not suitable for {$u['code']} (use, venue type or facilities).");
                }
                foreach ($slots as $key) {
                    if (isset($v['blocked'][$key])) {
                        $out[] = $this->v('venue', [$id], "{$v['name']} cannot be used at {$this->when($key)}: {$v['blocked'][$key]}.");
                    }
                }
            }
        }

        foreach ($atSlot as $key => $ids) {
            $n = count($ids);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $p->units[$ids[$i]];
                    $b = $p->units[$ids[$j]];
                    $shared = $a['item'] === $b['item'] ? max(1, $a['size']) : $p->clash($a['item'], $b['item']);
                    if ($shared > 0) {
                        $out[] = $this->v('students', [$ids[$i], $ids[$j]], "{$a['code']} and {$b['code']} are both at {$this->when($key)}; {$shared} student(s) take both.");
                    }
                }
            }
        }
        foreach ($staffAt as $s => $bySlot) {
            foreach ($bySlot as $key => $ids) {
                if (count(array_unique($ids)) > 1) {
                    $out[] = $this->v('staff', array_values(array_unique($ids)), ($p->staffNames[$s] ?? 'A lecturer') . ' has ' . implode(' and ', array_map(fn ($x) => $p->units[$x]['code'], array_unique($ids))) . " at the same time ({$this->when($key)}).");
                }
            }
        }
        foreach ($venueAt as $vid => $bySlot) {
            $v = $p->venues[$vid] ?? null;
            if (! $v) {
                continue;
            }
            foreach ($bySlot as $key => $users) {
                $codes = implode(', ', array_map(fn ($x) => $p->units[$x]['code'], array_keys($users)));
                if (count($users) > 1 && (! $p->isExam() || ! $v['shared'])) {
                    $out[] = $this->v('room', array_keys($users), "{$v['name']} is double-booked at {$this->when($key)} ({$codes}).");
                } elseif (array_sum($users) > $v['cap']) {
                    $out[] = $this->v('capacity', array_keys($users), "{$v['name']} has " . array_sum($users) . " people at {$this->when($key)} but holds {$v['cap']} ({$codes}).");
                }
            }
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    private function v(string $rule, array $units, string $message): array
    {
        return ['rule' => $rule, 'units' => $units, 'message' => $message];
    }

    private function when(string $key): string
    {
        $s = $this->p->slots[$key];
        $day = $s['date'] ? date('D j M', strtotime($s['date'])) : (\App\Models\Timetable::DAYS[$s['day']] ?? '');

        return "{$day} {$s['start']}";
    }
}
