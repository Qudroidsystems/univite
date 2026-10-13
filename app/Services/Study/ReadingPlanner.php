<?php

namespace App\Services\Study;

/**
 * Builds a student's weekly reading timetable from the free time they give, minus their lectures, sharing the study
 * sessions between their courses by the criteria they chose:
 *  - units: courses with more credit units get more time;
 *  - timetable: courses that meet more often in the week (more ground to cover) get more time;
 *  - priority: the student's own ranking of each course (1 low .. 5 high);
 *  - difficulty: how hard the student finds each course (1 easy .. 5 hard);
 *  - exams: courses whose exam is coming soon get more time, and their sessions turn into revision;
 *  - target: courses where the GPA planner needs a high grade get more time.
 * Every course gets at least one session when there is room, a course is not studied twice in a row, and the
 * same course is spread over different days where possible. Pure: no database, so it is easy to test.
 */
class ReadingPlanner
{
    public const CRITERIA = ['units', 'timetable', 'priority', 'difficulty', 'exams', 'target'];

    /**
     * @param  array  $courses  [{id, code, title, units, lectures (per week), exam_date (Y-m-d|null), priority 1-5, difficulty 1-5, target_points|null}]
     * @param  array  $prefs  {free_time: [{day 1-7, start "HH:MM", end "HH:MM"}], session_minutes, break_minutes, max_per_day, criteria: [...], avoid_lectures: bool}
     * @param  array  $lectures  [{day, starts_at, ends_at}] the student's classes, kept clear (with 15 minutes either side)
     * @return array{sessions: array, summary: array, slots: int}
     */
    public function build(array $courses, array $prefs, array $lectures, float $maxPoints = 5.0, ?string $today = null): array
    {
        $len = max(20, min(240, (int) ($prefs['session_minutes'] ?? 60)));
        $gap = max(0, min(120, (int) ($prefs['break_minutes'] ?? 15)));
        $perDay = max(1, min(12, (int) ($prefs['max_per_day'] ?? 3)));
        $criteria = array_values(array_intersect(self::CRITERIA, (array) ($prefs['criteria'] ?? ['units', 'timetable', 'priority', 'exams'])));
        $today = $today ? strtotime($today) : strtotime('today');

        $slots = $this->slots((array) ($prefs['free_time'] ?? []), ($prefs['avoid_lectures'] ?? true) ? $lectures : [], $len, $gap, $perDay);
        $courses = array_values($courses);
        if (! $courses || ! $slots) {
            return ['sessions' => [], 'summary' => [], 'slots' => count($slots)];
        }

        // Weight of each course: a little for everyone plus each chosen criterion scaled to 0..1.
        $maxUnits = max(1, max(array_map(fn ($c) => (int) $c['units'], $courses)));
        $maxLect = max(1, max(array_map(fn ($c) => (int) ($c['lectures'] ?? 0), $courses)));
        $weights = [];
        $reasons = [];
        $examDays = [];
        foreach ($courses as $i => $c) {
            $w = 0.25;
            $why = [];
            $days = ! empty($c['exam_date']) ? (int) floor((strtotime($c['exam_date']) - $today) / 86400) : null;
            $examDays[$i] = $days;
            foreach ($criteria as $k) {
                $f = match ($k) {
                    'units' => (int) $c['units'] / $maxUnits,
                    'timetable' => (int) ($c['lectures'] ?? 0) / $maxLect,
                    'priority' => (max(1, min(5, (int) ($c['priority'] ?? 3))) - 1) / 4,
                    'difficulty' => (max(1, min(5, (int) ($c['difficulty'] ?? 3))) - 1) / 4,
                    'exams' => $days !== null && $days >= 0 && $days <= 42 ? (42 - $days) / 42 : 0,
                    'target' => isset($c['target_points']) && $maxPoints > 0 ? (float) $c['target_points'] / $maxPoints : 0,
                };
                $w += $f;
                if ($f >= 0.75) {
                    $why[] = match ($k) {
                        'units' => $c['units'] . ' credit units',
                        'timetable' => ($c['lectures'] ?? 0) . ' lectures a week',
                        'priority' => 'your high priority',
                        'difficulty' => 'you find it hard',
                        'exams' => 'exam in ' . $days . ' day' . ($days === 1 ? '' : 's'),
                        'target' => 'you need a high grade',
                    };
                }
            }
            $weights[$i] = $w;
            $reasons[$i] = $why;
        }

        $quota = $this->share($weights, count($slots));

        // Fill the slots in time order: the course with the most sessions left, not the one just studied, preferring
        // a course not yet studied that day.
        $left = $quota;
        $sessions = [];
        $prev = null;
        $doneToday = [];
        $count = array_fill(0, count($courses), 0);
        foreach ($slots as $k => $s) {
            if ($k > 0 && $slots[$k - 1]['day'] !== $s['day']) {
                $prev = null;   // a new day starts fresh
            }
            if (! isset($doneToday[$s['day']])) {
                $doneToday[$s['day']] = [];
            }
            $cands = array_keys(array_filter($left, fn ($n) => $n > 0));
            if (! $cands) {
                break;
            }
            usort($cands, function ($a, $b) use ($left, $prev, $doneToday, $s, $weights) {
                $pa = ($a === $prev ? 2 : 0) + (in_array($a, $doneToday[$s['day']], true) ? 1 : 0);
                $pb = ($b === $prev ? 2 : 0) + (in_array($b, $doneToday[$s['day']], true) ? 1 : 0);

                return [$pa, -$left[$a], -$weights[$a], $a] <=> [$pb, -$left[$b], -$weights[$b], $b];
            });
            $i = $cands[0];
            $left[$i]--;
            $count[$i]++;
            $doneToday[$s['day']][] = $i;
            $prev = $i;
            $c = $courses[$i];
            $kind = ($examDays[$i] !== null && $examDays[$i] >= 0 && $examDays[$i] <= 14) ? 'revision' : ($count[$i] % 3 === 0 ? 'practice' : 'reading');
            $sessions[] = ['day' => $s['day'], 'starts_at' => $this->hm($s['start']), 'ends_at' => $this->hm($s['end']), 'course_id' => $c['id'] ?? null,
                'code' => $c['code'] ?? '', 'title' => $c['title'] ?? '', 'kind' => $kind];
        }

        $summary = [];
        foreach ($courses as $i => $c) {
            $summary[] = ['course_id' => $c['id'] ?? null, 'code' => $c['code'] ?? '', 'title' => $c['title'] ?? '', 'sessions' => $count[$i], 'minutes' => $count[$i] * $len,
                'weight' => round($weights[$i], 2), 'reasons' => $reasons[$i]];
        }
        usort($summary, fn ($a, $b) => [$b['minutes'], $a['code']] <=> [$a['minutes'], $b['code']]);

        return ['sessions' => $sessions, 'summary' => $summary, 'slots' => count($slots)];
    }

    /** Sessions per course in proportion to weight (largest remainder), at least one each when there are enough slots. */
    private function share(array $weights, int $slots): array
    {
        $n = count($weights);
        if ($slots < $n) {   // fewer slots than courses: the heaviest courses get one each
            $quota = array_fill(0, $n, 0);
            arsort($weights);
            foreach (array_slice(array_keys($weights), 0, $slots) as $i) {
                $quota[$i] = 1;
            }

            return $quota;
        }
        $quota = array_fill(0, $n, 1);
        $slots -= $n;
        if ($slots === 0) {
            return $quota;
        }
        $total = array_sum($weights);
        $exact = array_map(fn ($w) => $slots * $w / $total, $weights);
        $floor = array_map(fn ($x) => (int) floor($x), $exact);
        foreach ($floor as $i => $f) {
            $quota[$i] += $f;
        }
        $rest = $slots - array_sum($floor);
        $rem = [];
        foreach ($exact as $i => $x) {
            $rem[$i] = $x - $floor[$i];
        }
        arsort($rem);
        foreach (array_slice(array_keys($rem), 0, $rest) as $i) {
            $quota[$i]++;
        }

        return $quota;
    }

    /** The study slots in the week: each free window minus lectures, cut into sessions with breaks, a few per day. */
    private function slots(array $free, array $lectures, int $len, int $gap, int $perDay): array
    {
        $busy = [];
        foreach ($lectures as $l) {
            $busy[(int) $l['day']][] = [$this->min($l['starts_at']) - 15, $this->min($l['ends_at']) + 15];
        }
        $windows = [];
        foreach ($free as $f) {
            $day = (int) ($f['day'] ?? 0);
            $a = $this->min($f['start'] ?? '');
            $b = $this->min($f['end'] ?? '');
            if ($day < 1 || $day > 7 || $a === null || $b === null || $b <= $a) {
                continue;
            }
            $parts = [[$a, $b]];
            foreach ($busy[$day] ?? [] as [$x, $y]) {
                $next = [];
                foreach ($parts as [$p, $q]) {
                    if ($y <= $p || $x >= $q) {
                        $next[] = [$p, $q];
                        continue;
                    }
                    if ($x > $p) {
                        $next[] = [$p, $x];
                    }
                    if ($y < $q) {
                        $next[] = [$y, $q];
                    }
                }
                $parts = $next;
            }
            foreach ($parts as $p) {
                $windows[] = ['day' => $day, 'a' => $p[0], 'b' => $p[1]];
            }
        }
        usort($windows, fn ($x, $y) => [$x['day'], $x['a']] <=> [$y['day'], $y['a']]);

        $slots = [];
        $perDayCount = [];
        $seen = [];
        foreach ($windows as $w) {
            for ($t = $w['a']; $t + $len <= $w['b']; $t += $len + $gap) {
                if (($perDayCount[$w['day']] ?? 0) >= $perDay || isset($seen[$w['day'] . '-' . $t])) {
                    continue 2;
                }
                $seen[$w['day'] . '-' . $t] = true;
                $perDayCount[$w['day']] = ($perDayCount[$w['day']] ?? 0) + 1;
                $slots[] = ['day' => $w['day'], 'start' => $t, 'end' => $t + $len];
            }
        }

        return $slots;
    }

    private function min(?string $hm): ?int
    {
        return $hm !== null && preg_match('/^(\d{1,2}):(\d{2})/', $hm, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    private function hm(int $m): string
    {
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
}
