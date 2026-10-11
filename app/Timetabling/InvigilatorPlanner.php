<?php

namespace App\Timetabling;

use App\Models\LeaveRequest;
use App\Models\StaffUnavailability;
use App\Models\Timetable;
use App\Models\TimetableInvigilator;
use App\Models\User;

/**
 * Gives every hall in use at every sitting enough invigilators: max(minimum per hall, candidates / ratio), from the
 * invigilator pool, never two places at once, never while unavailable or on approved leave, at most N duties a day,
 * spreading duties evenly. Shortfalls are reported, not hidden.
 */
class InvigilatorPlanner
{
    public function plan(Timetable $t, Problem $p, array $assign): array
    {
        $c = $t->config ?? [];
        $ratio = max(1, (int) ($c['invigilator_ratio'] ?? 50));
        $min = max(1, (int) ($c['min_invigilators'] ?? 2));
        $perDay = max(1, (int) ($c['max_duties_per_day'] ?? 2));

        $pool = collect($c['invigilators']['users'] ?? [])->map(fn ($i) => (int) $i);
        if (! empty($c['invigilators']['role'])) {
            try {
                $pool = $pool->merge(User::role($c['invigilators']['role'])->pluck('id'));
            } catch (\Throwable) {
                // the role was renamed or deleted; the named users still count
            }
        }
        $pool = $pool->unique()->values();
        $names = User::whereIn('id', $pool)->where('is_disabled', false)->pluck('name', 'id')->all();
        $pool = $pool->filter(fn ($id) => isset($names[$id]))->values();

        // Busy: unavailability, approved leave, and teaching their own paper is fine (they may invigilate it).
        $busy = [];
        foreach (StaffUnavailability::whereIn('user_id', $pool)->get() as $u) {
            foreach ($p->slots as $key => $slot) {
                $dayMatch = $u->day && (int) $u->day === (int) $slot['day'];
                $dateMatch = $u->date_from && $slot['date'] >= $u->date_from->toDateString() && $slot['date'] <= ($u->date_to ?? $u->date_from)->toDateString();
                if (($dayMatch || $dateMatch) && ProblemBuilder::overlaps($slot['start'], $slot['end'], $u->starts_at ? substr($u->starts_at, 0, 5) : null, $u->ends_at ? substr($u->ends_at, 0, 5) : null)) {
                    $busy[$u->user_id][$key] = true;
                }
            }
        }
        $dates = array_filter(array_column($p->slots, 'date'));
        if ($dates) {
            foreach (LeaveRequest::whereIn('user_id', $pool)->where('status', 'approved')->whereNull('resumed_on')->whereDate('start_date', '<=', max($dates))->whereDate('expected_resumption', '>', min($dates))->get() as $l) {
                foreach ($p->slots as $key => $slot) {
                    if ($slot['date'] >= $l->start_date->toDateString() && $slot['date'] < $l->expected_resumption->toDateString()) {
                        $busy[$l->user_id][$key] = true;
                    }
                }
            }
        }

        // Halls in use per sitting, with their candidate counts.
        $halls = [];
        foreach ($assign as $a) {
            foreach ($a['venues'] as $vid => $seats) {
                $halls[$a['slots'][0]][$vid] = ($halls[$a['slots'][0]][$vid] ?? 0) + $seats;
            }
        }
        uksort($halls, fn ($a, $b) => $p->slots[$a]['order'] <=> $p->slots[$b]['order']);

        $t->invigilators()->delete();
        $load = array_fill_keys($pool->all(), 0);
        $daily = [];
        $short = [];
        $needed = 0;
        $given = 0;
        foreach ($halls as $key => $venues) {
            $slot = $p->slots[$key];
            $used = [];
            arsort($venues);
            foreach ($venues as $vid => $n) {
                $need = max($min, (int) ceil($n / $ratio));
                $needed += $need;
                $got = 0;
                $free = array_filter(array_keys($load), fn ($id) => ! isset($used[$id]) && ! isset($busy[$id][$key]) && ($daily[$id][$slot['group']] ?? 0) < $perDay);
                usort($free, fn ($a, $b) => [$load[$a], $a] <=> [$load[$b], $b]);
                foreach (array_slice($free, 0, $need) as $uid) {
                    TimetableInvigilator::create([
                        'timetable_id' => $t->id, 'slot' => $key, 'date' => $slot['date'], 'starts_at' => $slot['start'], 'ends_at' => $slot['end'],
                        'venue_id' => $vid, 'user_id' => $uid, 'role' => $got === 0 ? 'chief' : 'assistant',
                    ]);
                    $used[$uid] = true;
                    $load[$uid]++;
                    $daily[$uid][$slot['group']] = ($daily[$uid][$slot['group']] ?? 0) + 1;
                    $got++;
                }
                $given += $got;
                if ($got < $need) {
                    $short[] = date('D j M', strtotime($slot['date'])) . " {$slot['start']}, " . ($p->venues[$vid]['name'] ?? 'hall') . ": needs {$need}, only {$got} available";
                }
            }
        }

        return [
            'pool' => $pool->count(), 'needed' => $needed, 'assigned' => $given, 'short' => $short,
            'advice' => $short ? 'Add staff to the invigilator pool, allow more duties a day, raise candidates per invigilator, or spread papers over more sittings.' : null,
        ];
    }
}
