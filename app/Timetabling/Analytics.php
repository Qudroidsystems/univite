<?php

namespace App\Timetabling;

/**
 * The statistics a committee reads to judge a timetable (SRS 8.2): sessions required, placed and left out, hard rule
 * breaks, soft preferences met and missed (by preference, with their cost), lecturer workload, each class's daily load
 * and idle gaps, venue and laboratory use, locked sessions and the score. Worked out from a solver holding the
 * placement, so a draft's figures follow every change.
 */
final class Analytics
{
    public static function of(Problem $p, Solver $s, array $extra = []): array
    {
        $violations = (new Checker($p))->check($s->assign);
        $soft = [];
        $withIssues = 0;
        $issueRows = [];
        foreach (array_keys($s->assign) as $id) {
            $issues = $s->softIssues($id);
            if ($issues) {
                $withIssues++;
                $issueRows[] = ['unit' => $id, 'code' => $p->units[$id]['code'], 'issues' => $issues];
            }
            foreach ($issues as $i) {
                $soft[$i['code']] ??= ['code' => $i['code'], 'label' => $i['label'], 'count' => 0, 'cost' => 0.0];
                $soft[$i['code']]['count']++;
                $soft[$i['code']]['cost'] += $i['cost'];
            }
        }
        usort($issueRows, fn ($a, $b) => array_sum(array_column($b['issues'], 'cost')) <=> array_sum(array_column($a['issues'], 'cost')));

        // Lecturers: periods a week, days taught, busiest day.
        $staff = [];
        foreach ($s->assign as $id => $a) {
            $day = $p->slots[$a['slots'][0]]['group'];
            foreach ($p->units[$id]['staff'] as $uid) {
                $staff[$uid] ??= ['name' => $p->staffNames[$uid] ?? "Staff #{$uid}", 'periods' => 0, 'days' => [], 'busiest' => 0];
                $staff[$uid]['periods'] += count($a['slots']);
                $staff[$uid]['days'][$day] = ($staff[$uid]['days'][$day] ?? 0) + count($a['slots']);
            }
        }
        foreach ($staff as &$row) {
            $row['busiest'] = $row['days'] ? max($row['days']) : 0;
            $row['days'] = count($row['days']);
        }
        unset($row);
        uasort($staff, fn ($a, $b) => $b['periods'] <=> $a['periods']);
        $loads = array_column($staff, 'periods');

        // Classes (programme + level cohorts): periods a day, idle gaps.
        $cohorts = [];
        foreach ($s->assign as $id => $a) {
            $day = $p->slots[$a['slots'][0]]['group'];
            foreach ($p->units[$id]['cohorts'] as $c) {
                foreach ($a['slots'] as $k) {
                    $cohorts[$c][$day][$p->slots[$k]['pos']] = true;
                }
            }
        }
        $cohortRows = [];
        foreach ($cohorts as $c => $days) {
            $gaps = 0;
            $max = 0;
            $total = 0;
            foreach ($days as $positions) {
                $keys = array_keys($positions);
                sort($keys);
                $gaps += end($keys) - $keys[0] + 1 - count($keys);
                $max = max($max, count($keys));
                $total += count($keys);
            }
            $cohortRows[] = ['name' => $p->cohortNames[$c] ?? $c, 'periods' => $total, 'days' => count($days), 'max_per_day' => $max, 'gaps' => $gaps];
        }
        usort($cohortRows, fn ($a, $b) => [$b['gaps'], $b['max_per_day']] <=> [$a['gaps'], $a['max_per_day']]);

        // Venues: share of the week's periods each is used.
        $slotCount = max(1, count($p->slots) - count($p->protected));
        $used = [];
        foreach ($s->assign as $a) {
            foreach ($a['venues'] as $vid => $_) {
                $used[$vid] = ($used[$vid] ?? 0) + count($a['slots']);
            }
        }
        $venues = [];
        foreach ($p->venues as $vid => $v) {
            $venues[] = ['name' => $v['name'], 'code' => $v['code'], 'type' => $v['type'], 'cap' => $v['cap'], 'used' => $used[$vid] ?? 0,
                'percent' => round(($used[$vid] ?? 0) / $slotCount * 100, 1), 'lab' => in_array($v['type'], ['laboratory', 'computer_lab', 'workshop', 'studio', 'lab'], true)];
        }
        usort($venues, fn ($a, $b) => $b['percent'] <=> $a['percent']);
        $labs = array_filter($venues, fn ($v) => $v['lab']);
        $occupancy = $venues ? round(array_sum(array_column($venues, 'used')) / ($slotCount * count($venues)) * 100, 1) : 0.0;

        $placed = count($s->assign);

        return $extra + [
            'required' => count($p->units), 'placed' => $placed, 'unplaced' => count($p->units) - $placed,
            'hard_violations' => count($violations), 'hard' => array_slice(array_column($violations, 'message'), 0, 30),
            'soft_missed' => array_sum(array_column($soft, 'count')), 'soft_met' => $placed - $withIssues, 'soft' => array_values($soft),
            'soft_worst' => array_slice($issueRows, 0, 25),
            'score' => $s->score(), 'locked' => count($p->fixed), 'seconds' => $s->seconds, 'algorithm' => $s->algorithm,
            'lecturers' => ['count' => count($staff), 'min' => $loads ? min($loads) : 0, 'max' => $loads ? max($loads) : 0,
                'average' => $loads ? round(array_sum($loads) / count($loads), 1) : 0, 'rows' => array_slice(array_values($staff), 0, 40)],
            'classes' => ['count' => count($cohortRows), 'gaps' => array_sum(array_column($cohortRows, 'gaps')),
                'max_per_day' => $cohortRows ? max(array_column($cohortRows, 'max_per_day')) : 0, 'rows' => array_slice($cohortRows, 0, 40)],
            'venues' => ['count' => count($venues), 'occupancy' => $occupancy, 'labs' => count($labs),
                'lab_occupancy' => $labs ? round(array_sum(array_column($labs, 'used')) / ($slotCount * count($labs)) * 100, 1) : 0.0, 'rows' => array_slice($venues, 0, 40)],
        ];
    }
}
