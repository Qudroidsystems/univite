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
 *
 * The committee chooses how hard it searches (ALGORITHMS): constraint satisfaction with repair (quick), the same with
 * more rounds of local search (thorough), or a hybrid that adds simulated annealing, which also accepts some worse moves
 * early on so the search can leave a local optimum. softIssues() explains, placement by placement, which preferences a
 * timetable does not meet and what that costs.
 */
class Solver
{
    /** key => [label, description] */
    public const ALGORITHMS = [
        'quick' => ['Constraint satisfaction (quick)', 'Places the hardest classes first at their best feasible time, repairs blockages by moving one or two classes, then makes two rounds of improvements. Seconds even for a whole university.'],
        'thorough' => ['Constraint satisfaction with local search', 'The same, followed by up to eight rounds of moving each class to a cheaper feasible place. Slower; usually fewer gaps and early or late periods.'],
        'hybrid' => ['Hybrid: constraints + simulated annealing', 'Builds a feasible timetable, then explores thousands of feasible moves, sometimes accepting a worse one early on, and keeps the best timetable found. Slowest; best for hard timetables.'],
    ];

    /** Soft preference codes softIssues() reports => label. */
    public const SOFT = [
        'early_late' => 'Very early or very late period', 'spread' => 'Two meetings of a course on one day', 'gaps' => 'Idle gaps in a class\'s day',
        'consecutive' => 'Long run of back-to-back periods', 'daily_load' => 'More periods in a day than the cap', 'breaks' => 'Next to a break',
        'preferences' => 'Not the preferred or departmental venue', 'priority' => 'High-priority course late in the day',
    ];

    public float $seconds = 0.0;
    public string $algorithm = 'quick';
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

    /** Solve with one of ALGORITHMS. */
    public function run(string $algorithm = 'quick', int $iterations = 4000): void
    {
        $started = microtime(true);
        $this->algorithm = isset(self::ALGORITHMS[$algorithm]) ? $algorithm : 'quick';
        if ($this->algorithm === 'hybrid') {
            $this->solve(3);
            $this->anneal($iterations);
        } else {
            $this->solve($this->algorithm === 'thorough' ? 8 : 2);
        }
        $this->seconds = round(microtime(true) - $started, 2);
    }

    /**
     * Simulated annealing over feasible moves: take a random placed class, try one of its cheapest feasible places, and
     * keep the move when it lowers the score, or (with a chance that shrinks as the search cools) even when it does
     * not. The best timetable seen is kept. Every move is feasible, so hard rules always hold.
     */
    public function anneal(int $iterations = 4000, float $temperature = 25.0): void
    {
        $ids = array_values(array_diff(array_keys($this->assign), array_keys($this->p->fixed)));
        if (count($ids) < 2) {
            return;
        }
        $current = $this->score();
        $best = $current;
        $bestAssign = $this->assign;
        $cool = pow(0.05 / $temperature, 1 / max(1, $iterations));
        for ($i = 0; $i < $iterations; $i++) {
            $id = $ids[mt_rand(0, count($ids) - 1)];
            $was = $this->assign[$id];
            $before = $this->timeCost($id, $was['slots']) + $this->venueCost($id, $was['slots'], $was['venues']);
            $this->remove($id);
            $options = [];
            $this->best($id, 6, $options);
            if (! $options) {
                $this->place($id, $was);
                continue;
            }
            $pick = $options[mt_rand(0, count($options) - 1)];
            $delta = $pick['cost'] - $before;
            if ($delta < 0 || mt_rand() / mt_getrandmax() < exp(-$delta / max(0.01, $temperature))) {
                $this->place($id, ['slots' => $pick['slots'], 'venues' => $pick['venues']]);
                $current += $delta;
                // The local delta leaves out effects on whole classes (gaps, daily load), so a promising move is
                // confirmed with the full score before it is kept as the best, and the running figure is corrected.
                if ($current < $best - 0.01) {
                    $current = $this->score();
                    if ($current < $best - 0.01) {
                        $best = $current;
                        $bestAssign = $this->assign;
                    }
                }
            } else {
                $this->place($id, $was);
            }
            $temperature *= $cool;
        }
        if ($this->score() > $best + 0.01) {
            foreach (array_keys($this->assign) as $id) {
                isset($this->p->fixed[$id]) || $this->remove($id);
            }
            foreach ($bestAssign as $id => $a) {
                isset($this->assign[$id]) || $this->place($id, $a);
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

        $priority = $this->p->applyPriorities ? (($u['priority'] ?? 3) - 3) * 12 : 0;

        return $clashes * 2 + $u['size'] / $this->p->maxSize * 10 + $special + $u['length'] * 2 + 10 / $suitable + count($u['staff']) + $priority;
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
        // High-priority courses get the earlier, fresher periods; low-priority ones take what is left.
        if ($p->applyPriorities && ($u['priority'] ?? 3) !== 3 && $groupSize > 1) {
            $cost += (($u['priority'] ?? 3) - 3) * 4 * ($first['pos'] / ($groupSize - 1));
        }
        if ($p->w('breaks') && (! empty($first['nearBreak']) || ! empty($last['nearBreak']))) {
            $cost += $p->w('breaks') * 4;
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

        // Cohort (programme-level) days: the daily cap, idle gaps, long runs and building changes.
        foreach ($u['cohorts'] as $cohort) {
            if ($p->maxPerDay > 0) {
                $load = count($slots);
                foreach ($this->groupUnits[$group] ?? [] as $other => $_) {
                    if ($other !== $id && in_array($cohort, $p->units[$other]['cohorts'], true)) {
                        $load += count($this->assign[$other]['slots']);
                    }
                }
                if ($load > $p->maxPerDay) {
                    $cost += 60 * ($load - $p->maxPerDay);
                }
            }
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

    /**
     * The preferences one placed class does not meet, each with what it adds to the score: what a committee reads to
     * judge whether to accept the timetable or move the class.
     *
     * @return array<int, array{code: string, label: string, text: string, cost: float}>
     */
    public function softIssues(string $id): array
    {
        $p = $this->p;
        $a = $this->assign[$id] ?? null;
        if (! $a || $p->isExam()) {
            return [];
        }
        $u = $p->units[$id];
        $first = $p->slots[$a['slots'][0]];
        $last = $p->slots[end($a['slots'])];
        $group = $first['group'];
        $size = count($p->groups[$group]);
        $out = [];
        $add = function (string $code, string $text, float $cost) use (&$out) {
            $cost > 0 && $out[] = ['code' => $code, 'label' => self::SOFT[$code], 'text' => $text, 'cost' => round($cost, 1)];
        };
        if ($size > 2 && $first['pos'] === 0) {
            $add('early_late', "Starts in the first period of the day ({$first['start']}).", $p->w('early_late') * 6);
        }
        if ($size > 2 && $last['pos'] === $size - 1) {
            $add('early_late', "Ends in the last period of the day ({$last['end']}).", $p->w('early_late') * 6);
        }
        foreach ($this->groupUnits[$group] ?? [] as $other => $_) {
            if ($other !== $id && $p->units[$other]['item'] === $u['item']) {
                $add('spread', 'Another meeting of ' . $u['code'] . ' is on the same day.', $p->w('spread') * 30);
                break;
            }
        }
        if ($p->w('breaks') && (! empty($first['nearBreak']) || ! empty($last['nearBreak']))) {
            $add('breaks', 'Is right next to a break.', $p->w('breaks') * 4);
        }
        if ($p->applyPriorities && ($u['priority'] ?? 3) >= 4 && $size > 1 && $first['pos'] / ($size - 1) > 0.6) {
            $add('priority', "A priority {$u['priority']} course placed late in the day ({$first['start']}).", (($u['priority'] ?? 3) - 3) * 4 * ($first['pos'] / ($size - 1)));
        }
        foreach ($u['cohorts'] as $cohort) {
            $name = $p->cohortNames[$cohort] ?? 'a class';
            $load = 0;
            $pos = [];
            foreach ($this->groupUnits[$group] ?? [] as $other => $_) {
                if (in_array($cohort, $p->units[$other]['cohorts'], true)) {
                    foreach ($this->assign[$other]['slots'] as $k) {
                        $pos[$p->slots[$k]['pos']] = true;
                        $load++;
                    }
                }
            }
            if ($p->maxPerDay > 0 && $load > $p->maxPerDay) {
                $add('daily_load', "{$name} has {$load} periods on " . (\App\Models\Timetable::DAYS[$first['day']] ?? 'this day') . "; the cap is {$p->maxPerDay}.", 60 * ($load - $p->maxPerDay));
            }
            ksort($pos);
            $keys = array_keys($pos);
            $gaps = $keys ? (end($keys) - $keys[0] + 1 - count($keys)) : 0;
            if ($gaps >= 2) {
                $add('gaps', "{$name} has {$gaps} idle periods that day.", $p->w('gaps') * 3 * $gaps);
            }
            $run = 1;
            $longest = 1;
            for ($k = 1; $k < count($keys); $k++) {
                $run = $keys[$k] === $keys[$k - 1] + 1 ? $run + 1 : 1;
                $longest = max($longest, $run);
            }
            if ($longest > $p->maxConsecutive) {
                $add('consecutive', "{$name} has {$longest} periods in a row (most allowed {$p->maxConsecutive}).", $p->w('consecutive') * 10 * ($longest - $p->maxConsecutive));
            }
        }
        foreach ($a['venues'] as $vid => $_) {
            $v = $p->venues[$vid] ?? null;
            if ($u['preferred'] && $u['preferred'] !== $vid) {
                $add('preferences', 'Not in its preferred venue.', $p->w('preferences') * 8);
            } elseif ($v && $v['dept'] && $u['dept'] && $v['dept'] !== $u['dept']) {
                $add('preferences', "In {$v['name']}, which belongs to another department.", $p->w('preferences') * 3);
            }
        }

        return array_values(array_unique($out, SORT_REGULAR));
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
