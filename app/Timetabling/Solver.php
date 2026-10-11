<?php

namespace App\Timetabling;

/**
 * Places every unit without breaking a hard rule, then improves the soft score.
 *
 * 1. Hardest units first (most clashes, largest, needing special rooms), each at its cheapest feasible time and venue.
 * 2. A unit with no feasible place tries to make room by moving one or two units that block it.
 * 3. A few rounds of local search move units to cheaper places. A move is only made if it is still feasible.
 * Hard rules are checked on every placement, so the result never trades a hard rule for a better score. Anything that
 * cannot be placed is left out with an explanation and suggestions (see diagnose()).
 */
class Solver
{
    /** @var array<string, array{slots: array<int, string>, venues: array<int, int>}> */
    public array $assign = [];
    /** @var array<string, array> unitId => diagnosis */
    public array $unplaced = [];

    private array $atSlot = [];
    private array $venueUse = [];
    private array $staffAt = [];
    private array $venueLoad = [];
    private array $groupUnits = [];
    private array $groupIndex = [];
    private array $suit = [];

    public function __construct(private Problem $p)
    {
        mt_srand($p->seed);
        $this->groupIndex = array_flip(array_keys($p->groups));
        foreach ($p->units as $id => $u) {
            $this->suit[$id] = $p->suitableVenues($u);
        }
    }

    /** Start from existing placements (to check or adjust a saved timetable). */
    public function load(array $assign): void
    {
        foreach ($assign as $id => $a) {
            if (isset($this->p->units[$id])) {
                $this->place($id, $a);
            }
        }
    }

    public function solve(int $rounds = 2): void
    {
        foreach ($this->p->fixed as $id => $a) {
            $this->place($id, $a);
        }
        $todo = array_diff(array_keys($this->p->units), array_keys($this->assign));
        usort($todo, fn ($a, $b) => $this->difficulty($b) <=> $this->difficulty($a));

        $left = [];
        foreach ($todo as $id) {
            $best = $this->best($id);
            if ($best) {
                $this->place($id, $best);
            } elseif (! $this->repair($id)) {
                $left[] = $id;
            }
        }
        // Second chance: room may have been freed while repairing others.
        foreach ($left as $i => $id) {
            if (($best = $this->best($id)) || $this->repair($id)) {
                $best && $this->place($id, $best);
                unset($left[$i]);
            }
        }

        $this->improve($rounds);

        foreach ($left as $id) {
            $this->unplaced[$id] = $this->diagnose($id);
        }
    }

    // ---- search ---------------------------------------------------------------------------------------

    private function difficulty(string $id): float
    {
        $u = $this->p->units[$id];
        $clashes = count($this->p->conflicts[$u['item']] ?? []);
        $special = ($u['venueType'] ? 3 : 0) + count($u['facilities']) * 2;
        $suitable = max(1, count($this->suit[$id]));

        return $clashes * 2 + $u['size'] / $this->p->maxSize * 10 + $special + $u['length'] * 2 + 10 / $suitable + count($u['staff']);
    }

    /** Cheapest feasible placement, or null. */
    public function best(string $id, ?int $limit = null, array &$all = []): ?array
    {
        $u = $this->p->units[$id];
        $best = null;
        $bestCost = INF;
        foreach ($this->p->starts($u) as $slots) {
            if ($this->timeProblem($id, $slots) !== null) {
                continue;
            }
            $venue = $this->venueFor($id, $slots);
            if (! $venue) {
                continue;
            }
            $cost = $this->timeCost($id, $slots) + $venue[1] + mt_rand(0, 100) / 10000;
            if ($limit) {
                $all[] = ['slots' => $slots, 'venues' => $venue[0], 'cost' => round($cost, 2)];
            }
            if ($cost < $bestCost) {
                $bestCost = $cost;
                $best = ['slots' => $slots, 'venues' => $venue[0]];
            }
        }
        if ($limit) {
            usort($all, fn ($a, $b) => $a['cost'] <=> $b['cost']);
            $all = array_slice($all, 0, $limit);
        }

        return $best;
    }

    /** Every feasible place for a unit given everything else stays put, cheapest first. */
    public function alternatives(string $id, int $limit = 12): array
    {
        $had = $this->assign[$id] ?? null;
        $had && $this->remove($id);
        $all = [];
        $this->best($id, $limit, $all);
        $had && $this->place($id, $had);

        return $all;
    }

    /** Make room by moving the one or two units that block a time, if they can go elsewhere. */
    private function repair(string $id): bool
    {
        $u = $this->p->units[$id];
        $options = [];
        foreach ($this->p->starts($u) as $slots) {
            $blockers = $this->blockers($id, $slots);
            if ($blockers === null || ! $blockers || count($blockers) > 2) {
                continue;
            }
            $options[] = [$slots, $blockers];
        }
        usort($options, fn ($a, $b) => count($a[1]) <=> count($b[1]));

        foreach (array_slice($options, 0, 15) as [$slots, $blockers]) {
            $saved = [];
            foreach ($blockers as $b) {
                $saved[$b] = $this->assign[$b];
                $this->remove($b);
            }
            $venue = $this->venueFor($id, $slots);
            if ($venue) {
                $this->place($id, ['slots' => $slots, 'venues' => $venue[0]]);
                $moved = [];
                foreach ($blockers as $b) {
                    $alt = $this->best($b);
                    if (! $alt) {
                        break;
                    }
                    $this->place($b, $alt);
                    $moved[] = $b;
                }
                if (count($moved) === count($blockers)) {
                    return true;
                }
                foreach ($moved as $b) {
                    $this->remove($b);
                }
                $this->remove($id);
            }
            foreach ($saved as $b => $a) {
                $this->place($b, $a);
            }
        }

        return false;
    }

    /** Units whose students or lecturers clash with this time (null if the time is impossible for other reasons). */
    private function blockers(string $id, array $slots): ?array
    {
        $u = $this->p->units[$id];
        $out = [];
        foreach ($slots as $key) {
            $slot = $this->p->slots[$key];
            if (isset($this->p->protected[$key]) || ($this->p->isExam() && $u['minutes'] > $slot['minutes'])) {
                return null;
            }
            foreach ($u['staff'] as $s) {
                if (isset($this->p->staffBusy[$s][$key])) {
                    return null;
                }
                if (isset($this->staffAt[$s][$key])) {
                    $out[$this->staffAt[$s][$key]] = true;
                }
            }
            foreach ($this->atSlot[$key] ?? [] as $other => $_) {
                if ($this->clashes($u, $this->p->units[$other])) {
                    $out[$other] = true;
                }
            }
        }
        foreach (array_keys($out) as $b) {
            if (isset($this->p->fixed[$b])) {
                return null;
            }
        }

        return array_keys($out);
    }

    private function improve(int $rounds): void
    {
        $ids = array_values(array_diff(array_keys($this->assign), array_keys($this->p->fixed)));
        for ($r = 0; $r < $rounds; $r++) {
            shuffle($ids);
            $changed = false;
            foreach ($ids as $id) {
                $current = $this->assign[$id];
                $this->remove($id);
                $now = $this->timeCost($id, $current['slots']) + $this->venueCost($id, $current['slots'], $current['venues']);
                $best = $this->best($id);
                if ($best) {
                    $cost = $this->timeCost($id, $best['slots']) + $this->venueCost($id, $best['slots'], $best['venues']);
                    if ($cost < $now - 0.5) {
                        $this->place($id, $best);
                        $changed = true;
                        continue;
                    }
                }
                $this->place($id, $current);
            }
            if (! $changed) {
                break;
            }
        }
    }

    // ---- hard rules -----------------------------------------------------------------------------------

    private function clashes(array $a, array $b): bool
    {
        return $a['item'] === $b['item'] || $this->p->clash($a['item'], $b['item']) > 0;
    }

    /** Why this time cannot work for the unit (ignoring venues), or null if it can. */
    public function timeProblem(string $id, array $slots): ?string
    {
        $u = $this->p->units[$id];
        foreach ($slots as $key) {
            $slot = $this->p->slots[$key] ?? null;
            if (! $slot) {
                return 'not a period in this timetable';
            }
            if (isset($this->p->protected[$key])) {
                return 'protected period: ' . $this->p->protected[$key];
            }
            if ($this->p->isExam() && $u['minutes'] > $slot['minutes']) {
                return "the paper takes {$u['minutes']} minutes but the sitting is {$slot['minutes']}";
            }
            foreach ($u['staff'] as $s) {
                if (isset($this->p->staffBusy[$s][$key])) {
                    return ($this->p->staffNames[$s] ?? 'A lecturer') . ' is ' . $this->p->staffBusy[$s][$key];
                }
                if (isset($this->staffAt[$s][$key]) && $this->staffAt[$s][$key] !== $id) {
                    return ($this->p->staffNames[$s] ?? 'A lecturer') . ' is already teaching ' . $this->p->units[$this->staffAt[$s][$key]]['code'];
                }
            }
            foreach ($this->atSlot[$key] ?? [] as $other => $_) {
                if ($other !== $id && $this->clashes($u, $this->p->units[$other])) {
                    $o = $this->p->units[$other];

                    return $o['item'] === $u['item'] ? 'another meeting of the same class is at this time' : $o['code'] . ' is at the same time (' . $this->p->clash($u['item'], $o['item']) . ' students take both)';
                }
            }
        }

        return null;
    }

    private function free(int $vid, string $key): int
    {
        $v = $this->p->venues[$vid];
        if (isset($v['blocked'][$key])) {
            return 0;
        }
        $use = $this->venueUse[$vid][$key] ?? [];
        if (! $this->p->isExam()) {
            return $use ? 0 : $v['cap'];
        }
        if ($use && ! $v['shared']) {
            return 0;
        }

        return max(0, $v['cap'] - array_sum($use));
    }

    private function freeOver(int $vid, array $slots): int
    {
        $min = PHP_INT_MAX;
        foreach ($slots as $key) {
            $min = min($min, $this->free($vid, $key));
        }

        return $min;
    }

    /** @return array{0: array<int, int>, 1: float}|null [venueId => seats], cost */
    private function venueFor(string $id, array $slots): ?array
    {
        $u = $this->p->units[$id];
        $size = max(1, $u['size']);
        $w = $this->p->w('balance');
        $total = max(1, count($this->p->slots));

        if (! $this->p->isExam()) {
            $best = null;
            $bestCost = INF;
            foreach ($this->suit[$id] as $vid => $v) {
                if ($v['cap'] < $size || $this->freeOver($vid, $slots) < $size) {
                    continue;
                }
                $cost = $this->venueCost($id, $slots, [$vid => $size]);
                if ($cost < $bestCost) {
                    $bestCost = $cost;
                    $best = [[$vid => $u['size']], $cost];
                }
            }

            return $best;
        }

        $free = [];
        foreach ($this->suit[$id] as $vid => $v) {
            $f = $this->freeOver($vid, $slots);
            $f > 0 && $free[$vid] = $f;
        }
        $single = null;
        $singleCost = INF;
        foreach ($free as $vid => $f) {
            if ($f >= $size) {
                $cost = $this->venueCost($id, $slots, [$vid => $size]);
                if ($cost < $singleCost) {
                    $singleCost = $cost;
                    $single = [[$vid => $u['size']], $cost];
                }
            }
        }
        if ($single) {
            return $single;
        }
        arsort($free);
        $parts = [];
        $left = $size;
        foreach ($free as $vid => $f) {
            if (count($parts) >= $this->p->maxSplit) {
                break;
            }
            $take = min($f, $left);
            $parts[$vid] = $take;
            $left -= $take;
            if ($left <= 0) {
                return [$parts, $this->venueCost($id, $slots, $parts)];
            }
        }

        return null;
    }

    // ---- soft score -----------------------------------------------------------------------------------

    private function venueCost(string $id, array $slots, array $venues): float
    {
        $u = $this->p->units[$id];
        $wb = $this->p->w('balance');
        $wp = $this->p->w('preferences');
        $total = max(1, count($this->p->slots));
        $cost = 0.0;
        foreach ($venues as $vid => $seats) {
            $v = $this->p->venues[$vid] ?? null;
            if (! $v) {
                continue;
            }
            $cost += $wb * 10 * max(0, $v['cap'] - $seats) / max(1, $v['cap']);
            $cost += $wb * 3 * ($this->venueLoad[$vid] ?? 0) / $total;
            if ($this->p->isExam() && ! empty($this->venueUse[$vid][$slots[0]])) {
                $cost -= $wb * 2; // filling a hall already open for this sitting saves invigilators
            }
            if ($v['dept'] && $u['dept'] && $v['dept'] !== $u['dept']) {
                $cost += $wp * 3;
            }
        }
        if (! $this->p->isExam() && $this->p->w('room_changes') && $u['cohorts'] && count($venues) === 1) {
            $building = $this->p->venues[array_key_first($venues)]['building'] ?? null;
            $group = $this->p->slots[$slots[0]]['group'];
            $a = $this->p->slots[$slots[0]]['pos'];
            $b = $this->p->slots[end($slots)]['pos'];
            foreach ($this->groupUnits[$group] ?? [] as $other => $_) {
                if (! array_intersect($u['cohorts'], $this->p->units[$other]['cohorts'])) {
                    continue;
                }
                $oa = $this->assign[$other];
                $pa = $this->p->slots[$oa['slots'][0]]['pos'];
                $pb = $this->p->slots[end($oa['slots'])]['pos'];
                $vid = array_key_first($oa['venues']);
                if (($pb === $a - 1 || $pa === $b + 1) && $vid && ($this->p->venues[$vid]['building'] ?? null) !== $building) {
                    $cost += $this->p->w('room_changes') * 5;
                }
            }
        }
        if ($u['preferred'] && ! isset($venues[$u['preferred']])) {
            $cost += $wp * 8;
        }
        $cost += max(0, count($venues) - 1) * ($wb * 8 + 4);

        return max(0, $cost);
    }

    private function timeCost(string $id, array $slots): float
    {
        $p = $this->p;
        $u = $p->units[$id];
        $first = $p->slots[$slots[0]];
        $last = $p->slots[end($slots)];
        $group = $first['group'];
        $groupSize = count($p->groups[$group]);
        $cost = 0.0;

        if ($p->isExam()) {
            if ($groupSize > 1 && $first['pos'] === $groupSize - 1) {
                $cost += $p->w('early_late') * 4;
            }
            $gi = $this->groupIndex[$group];
            foreach ($this->groupUnits as $g => $units) {
                $d = abs($this->groupIndex[$g] - $gi);
                if ($d > 1) {
                    continue;
                }
                foreach ($units as $other => $_) {
                    $shared = $p->clash($u['item'], $p->units[$other]['item']);
                    if ($shared > 0) {
                        $cost += $p->w('spread') * ($d === 0 ? 4 + min($shared, 100) / 10 : 1 + min($shared, 100) / 30);
                    }
                }
            }
            $n = max(1, count($p->groups) - 1);
            $cost += $p->w('large_first') * 10 * ($gi / $n) * ($u['size'] / $p->maxSize);

            return $cost;
        }

        if ($first['pos'] === 0 && $groupSize > 2) {
            $cost += $p->w('early_late') * 6;
        }
        if ($last['pos'] === $groupSize - 1 && $groupSize > 2) {
            $cost += $p->w('early_late') * 6;
        }
        $a = $first['pos'];
        $b = $last['pos'];
        foreach ($this->groupUnits[$group] ?? [] as $other => $_) {
            $o = $p->units[$other];
            if ($o['item'] === $u['item']) {
                $cost += $p->w('spread') * 30;
            }
        }

        // Cohort (programme-level) days: idle gaps, long runs and building changes.
        foreach ($u['cohorts'] as $cohort) {
            $blocks = [];
            foreach ($this->groupUnits[$group] ?? [] as $other => $_) {
                if (in_array($cohort, $p->units[$other]['cohorts'], true)) {
                    $oa = $this->assign[$other];
                    $blocks[$other] = [$p->slots[$oa['slots'][0]]['pos'], $p->slots[end($oa['slots'])]['pos']];
                }
            }
            if (! $blocks) {
                continue;
            }
            $gap = PHP_INT_MAX;
            $occupied = array_fill($a, $b - $a + 1, true);
            foreach ($blocks as $other => [$oa, $ob]) {
                $gap = min($gap, $ob < $a ? $a - $ob - 1 : ($oa > $b ? $oa - $b - 1 : 0));
                for ($k = $oa; $k <= $ob; $k++) {
                    $occupied[$k] = true;
                }
            }
            $cost += $p->w('gaps') * 3 * $gap;
            $run = 1;
            for ($k = $a - 1; isset($occupied[$k]); $k--) {
                $run++;
            }
            for ($k = $a + 1; isset($occupied[$k]); $k++) {
                $run++;
            }
            if ($run > $p->maxConsecutive) {
                $cost += $p->w('consecutive') * 10 * ($run - $p->maxConsecutive);
            }
        }
        foreach ($u['staff'] as $s) {
            $run = $b - $a + 1;
            for ($k = $a - 1; $k >= 0 && isset($this->staffAt[$s][$p->groups[$group][$k]]); $k--) {
                $run++;
            }
            for ($k = $b + 1; $k < $groupSize && isset($this->staffAt[$s][$p->groups[$group][$k]]); $k++) {
                $run++;
            }
            if ($run > $p->maxConsecutive) {
                $cost += $p->w('consecutive') * 6 * ($run - $p->maxConsecutive);
            }
        }

        return $cost;
    }

    // ---- bookkeeping ----------------------------------------------------------------------------------

    public function place(string $id, array $a): void
    {
        $this->assign[$id] = ['slots' => array_values($a['slots']), 'venues' => $a['venues']];
        $u = $this->p->units[$id];
        foreach ($a['slots'] as $key) {
            $this->atSlot[$key][$id] = true;
            foreach ($a['venues'] as $vid => $seats) {
                $this->venueUse[$vid][$key][$id] = $seats;
                $this->venueLoad[$vid] = ($this->venueLoad[$vid] ?? 0) + 1;
            }
            foreach ($u['staff'] as $s) {
                $this->staffAt[$s][$key] = $id;
            }
        }
        $this->groupUnits[$this->p->slots[$a['slots'][0]]['group']][$id] = true;
    }

    public function remove(string $id): void
    {
        $a = $this->assign[$id] ?? null;
        if (! $a) {
            return;
        }
        $u = $this->p->units[$id];
        foreach ($a['slots'] as $key) {
            unset($this->atSlot[$key][$id]);
            foreach ($a['venues'] as $vid => $seats) {
                unset($this->venueUse[$vid][$key][$id]);
                $this->venueLoad[$vid]--;
            }
            foreach ($u['staff'] as $s) {
                if (($this->staffAt[$s][$key] ?? null) === $id) {
                    unset($this->staffAt[$s][$key]);
                }
            }
        }
        unset($this->groupUnits[$this->p->slots[$a['slots'][0]]['group']][$id], $this->assign[$id]);
    }

    /** Total soft score of the current placement (lower is better). */
    public function score(): float
    {
        $total = 0.0;
        foreach (array_keys($this->assign) as $id) {
            $a = $this->assign[$id];
            $this->remove($id);
            $total += $this->timeCost($id, $a['slots']) + $this->venueCost($id, $a['slots'], $a['venues']);
            $this->place($id, $a);
        }

        return round($total, 1);
    }

    // ---- explanations ---------------------------------------------------------------------------------

    /** Why a unit could not be placed, and what would make it possible. */
    public function diagnose(string $id): array
    {
        $p = $this->p;
        $u = $p->units[$id];
        $size = max(1, $u['size']);
        $reasons = [];
        $suggest = [];
        $suit = $this->suit[$id];
        $what = $p->isExam() ? 'sitting' : 'period';
        $label = $p->isExam() ? 'paper' : 'class';

        $needs = trim(($u['venueType'] ? \App\Support\Lookups::label('venue_type', $u['venueType']) : '') . ($u['facilities'] ? ' with ' . implode(', ', array_map(fn ($f) => \App\Support\Lookups::label('venue_facility', $f), $u['facilities'])) : ''));
        if (! $suit) {
            $reasons[] = 'No venue in the register can be used for ' . ($p->isExam() ? 'examinations' : ($u['use'] === 'practical' ? 'practicals' : 'lectures')) . ($needs ? " as a {$needs}" : '') . '.';
            $suggest[] = 'Add a suitable venue, or change what this ' . $label . ' needs.';

            return ['reasons' => $reasons, 'suggestions' => $suggest, 'nearest' => null];
        }
        $caps = array_column($suit, 'cap');
        rsort($caps);
        $reach = $p->isExam() ? array_sum(array_slice($caps, 0, $p->maxSplit)) : ($caps[0] ?? 0);
        if ($reach < $size) {
            $largest = collect($suit)->sortByDesc('cap')->first();
            $reasons[] = $p->isExam()
                ? "{$size} candidates need seats, but even the {$p->maxSplit} largest suitable halls together seat only {$reach}."
                : "The class has {$size} students but the largest suitable venue ({$largest['name']}) holds {$largest['cap']}.";
            $suggest[] = $p->isExam() ? 'Allow a paper to use more halls, add examination seats, or split the paper into two sittings.' : 'Split the class into groups (add a group), or add or enlarge a venue.';
        }

        $count = ['protected' => 0, 'duration' => 0, 'staff' => [], 'students' => [], 'venue' => 0, 'all' => 0];
        $nearest = null;
        $nearestBad = INF;
        foreach ($p->starts($u) as $slots) {
            $count['all']++;
            $bad = 0;
            $why = null;
            foreach ($slots as $key) {
                $slot = $p->slots[$key];
                if (isset($p->protected[$key])) {
                    $count['protected']++;
                    continue 2;
                }
                if ($p->isExam() && $u['minutes'] > $slot['minutes']) {
                    $count['duration']++;
                    continue 2;
                }
                foreach ($u['staff'] as $s) {
                    if (isset($p->staffBusy[$s][$key]) || isset($this->staffAt[$s][$key])) {
                        $name = $p->staffNames[$s] ?? 'A lecturer';
                        $count['staff'][$name] = ($count['staff'][$name] ?? 0) + 1;
                        $bad += 50;
                        $why = $why ?? $name . ' is busy';
                    }
                }
                foreach ($this->atSlot[$key] ?? [] as $other => $_) {
                    $o = $p->units[$other];
                    if ($this->clashes($u, $o)) {
                        $shared = $o['item'] === $u['item'] ? $size : $p->clash($u['item'], $o['item']);
                        $count['students'][$o['code']] = max($count['students'][$o['code']] ?? 0, $shared);
                        $bad += $shared;
                        $why = $why ? $why . ', ' . $o['code'] : 'clashes with ' . $o['code'];
                    }
                }
            }
            $venue = $this->venueFor($id, $slots);
            if (! $venue) {
                $count['venue']++;
                $bad += 1000;
            }
            if ($bad > 0 && $bad < $nearestBad && $venue) {
                $nearestBad = $bad;
                $s0 = $p->slots[$slots[0]];
                $vn = implode(' + ', array_map(fn ($v) => $p->venues[$v]['code'], array_keys($venue[0])));
                $nearest = $this->slotLabel($s0, $p->slots[end($slots)]) . " in {$vn} would work except that it " . ($why ?? 'clashes') . '.';
            }
        }

        if ($count['all'] === 0) {
            $reasons[] = $p->isExam() ? 'The examination period has no sittings yet.' : "There is no run of {$u['length']} back-to-back periods on any day.";
            $suggest[] = $p->isExam() ? 'Set the examination dates and sittings.' : 'Add periods, or shorten the meeting.';
        }
        if ($count['duration']) {
            $reasons[] = "The paper lasts {$u['minutes']} minutes; {$count['duration']} of {$count['all']} sittings are shorter than that.";
            $count['duration'] === $count['all'] && $suggest[] = 'Lengthen a sitting or shorten the paper.';
        }
        if ($count['protected']) {
            $reasons[] = "{$count['protected']} {$what}s are protected (for example prayers or events).";
        }
        if ($count['students']) {
            arsort($count['students']);
            $top = array_slice($count['students'], 0, 4, true);
            $reasons[] = 'Its students already have something else in the free ' . $what . 's: ' . implode(', ', array_map(fn ($c, $n) => "{$c} ({$n} in common)", array_keys($top), $top)) . '.';
            $suggest[] = $p->isExam() ? 'Add an extra examination day or sitting, or move ' . array_key_first($top) . '.' : 'Add a period or a teaching day, or move ' . array_key_first($top) . '.';
        }
        foreach ($count['staff'] as $name => $n) {
            $reasons[] = "{$name} is unavailable or already busy in {$n} of the {$what}s.";
            $suggest[] = "Ask another lecturer to take a meeting, or relax {$name}'s unavailability.";
        }
        if ($count['venue'] && $reach >= $size) {
            $reasons[] = "In {$count['venue']} {$what}s every suitable venue is already taken" . ($needs ? " (it needs a {$needs})" : '') . '.';
            $suggest[] = $p->isExam() ? 'Add a sitting or day, allow more papers to share halls, or add halls.' : 'Add a period, free a suitable venue, or relax what the class needs.';
        }
        if (! $reasons) {
            $reasons[] = 'Every possible ' . $what . ' breaks at least one hard rule.';
        }

        return ['reasons' => $reasons, 'suggestions' => array_values(array_unique($suggest)), 'nearest' => $nearest];
    }

    private function slotLabel(array $first, array $last): string
    {
        $day = $first['date'] ? date('D j M', strtotime($first['date'])) : (\App\Models\Timetable::DAYS[$first['day']] ?? '');

        return $day . ' ' . $first['start'] . '–' . $last['end'];
    }
}
