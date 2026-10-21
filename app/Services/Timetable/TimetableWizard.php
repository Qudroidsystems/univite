<?php

namespace App\Services\Timetable;

use App\Models\Course;
use App\Models\ProgrammeCourse;
use App\Models\Timetable;
use App\Models\TimetableItem;
use App\Models\User;
use App\Services\TimetableService;
use App\Support\CourseType;
use App\Timetabling\DayStructure;
use App\Timetabling\Solver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The generation wizard: one form that sets up the whole school's lecture timetable at once (or only some faculties,
 * departments or levels) and generates it.
 *
 *  1. Scope: session, semester and what to schedule.
 *  2. Day structure: teaching days, when the day starts and ends, period length, breaks, half-days and events
 *     (assembly, fellowship, Jumat prayers, sports) that no class may take.
 *  3. Rules: most periods in a row, most periods a class has in a day, keeping off break-side periods, using only a
 *     department's own rooms, subject priorities and the preference weights.
 *  4. Course requirements: meetings a week and periods per meeting by credit units, then course by course (double
 *     periods, practicals, venue type, priority, leave a course out), or copied from an earlier timetable.
 *  5. Algorithm and the number of candidates, preference weights.
 *  6. Check: a feasibility run that saves nothing, then the draft is created and the candidates are generated (in the
 *     background) to compare, validate, apply, and submit for approval.
 *
 * The result is an ordinary draft timetable: the committee reviews it, moves things by hand, and sends it for approval.
 * The answers can be saved as a template and reused in later semesters.
 */
class TimetableWizard
{
    /** Default meetings a week and periods per meeting, by credit units. */
    public const UNIT_RULES = [1 => [1, 1], 2 => [1, 2], 3 => [3, 1], 4 => [2, 2], 5 => [2, 2], 6 => [3, 2]];

    public function __construct(private TimetableService $timetables, private TimetableRuns $runs)
    {
    }

    public static function defaults(): array
    {
        return [
            'days' => [1, 2, 3, 4, 5], 'day_start' => '08:00', 'day_end' => '18:00', 'period_minutes' => 60, 'max_periods' => null,
            'breaks' => [['after' => 4, 'minutes' => 60, 'label' => 'Lunch break']], 'half_days' => [], 'events' => [],
            'max_consecutive' => 3, 'max_per_day' => 6, 'avoid_breaks' => false, 'strict_rooms' => false, 'apply_priorities' => true,
            'compulsory_priority' => true, 'unit_rules' => self::UNIT_RULES, 'seed' => 1,
        ];
    }

    /** The day as periods, and the protected times (half-days and events) for the solver. */
    public function structure(array $d): array
    {
        $day = DayStructure::build($d['day_start'], $d['day_end'], (int) $d['period_minutes'], $d['breaks'] ?? [], ! empty($d['max_periods']) ? (int) $d['max_periods'] : null);
        $blocked = [];
        foreach ($d['half_days'] ?? [] as $h) {
            if (! empty($h['day']) && ! empty($h['ends_at'])) {
                $blocked[] = ['day' => (int) $h['day'], 'start' => substr($h['ends_at'], 0, 5), 'end' => '23:59', 'label' => trim((string) ($h['label'] ?? '')) ?: 'Half day'];
            }
        }
        foreach ($d['events'] ?? [] as $e) {
            if (! empty($e['day']) && ! empty($e['start']) && ! empty($e['end']) && $e['start'] < $e['end']) {
                $blocked[] = ['day' => (int) $e['day'], 'start' => substr($e['start'], 0, 5), 'end' => substr($e['end'], 0, 5), 'label' => trim((string) ($e['label'] ?? '')) ?: 'Event'];
            }
        }

        return ['periods' => $day['periods'], 'breaks' => $day['breaks'], 'blocked' => $blocked];
    }

    /**
     * Create the draft timetable from the wizard's answers (inside the caller's transaction).
     */
    private function build(array $d, User $by): Timetable
    {
        $t = $this->timetables->create([
            'kind' => 'lecture', 'name' => $d['name'], 'academic_session_id' => $d['academic_session_id'], 'semester_type_id' => $d['semester_type_id'],
            'departments' => $this->departments($d), 'levels' => $d['levels'] ?? [], 'programmes' => $d['programmes'] ?? [], 'committee' => $d['committee'] ?? [],
        ], $by);

        $s = $this->structure($d);
        $c = $t->config;
        $c['days'] = array_values(array_map('intval', $d['days']));
        $c['periods'] = $s['periods'];
        $c['blocked'] = $s['blocked'];
        $c['max_consecutive'] = (int) $d['max_consecutive'];
        $c['max_per_day'] = (int) ($d['max_per_day'] ?? 0);
        $c['strict_rooms'] = (bool) ($d['strict_rooms'] ?? false);
        $c['apply_priorities'] = (bool) ($d['apply_priorities'] ?? true);
        $c['seed'] = (int) ($d['seed'] ?? 1);
        $c['weights'] = array_map('intval', $d['weights'] ?? []) + ($c['weights'] ?? []);
        $c['weights']['breaks'] = ! empty($d['avoid_breaks']) ? max(1, (int) ($c['weights']['breaks'] ?? 0) ?: 2) : 0;
        // What the wizard was given, so the next timetable can start from it.
        $c['wizard'] = array_intersect_key($d, array_flip(['days', 'day_start', 'day_end', 'period_minutes', 'max_periods', 'breaks', 'half_days', 'events',
            'max_consecutive', 'max_per_day', 'avoid_breaks', 'strict_rooms', 'apply_priorities', 'compulsory_priority', 'unit_rules', 'faculties', 'departments', 'levels',
            'programmes', 'weights', 'algorithm', 'candidates']));
        $t->update(['config' => $c]);

        $this->requirements($t, $d);

        return $t->fresh();
    }

    /** Faculties chosen in the wizard stand for all their departments. @return int[] */
    private function departments(array $d): array
    {
        $ids = array_map('intval', $d['departments'] ?? []);
        if (! empty($d['faculties'])) {
            $ids = array_merge($ids, \App\Models\Department::whereIn('faculty_id', $d['faculties'])->pluck('id')->all());
        }

        return array_values(array_unique($ids));
    }

    /** Meetings and lengths by units, then a copied timetable's settings, then the course-by-course changes. */
    private function requirements(Timetable $t, array $d): void
    {
        $rules = [];
        foreach (($d['unit_rules'] ?? self::UNIT_RULES) as $units => $r) {
            $rules[(int) $units] = [max(1, min(10, (int) ($r[0] ?? $r['meetings'] ?? 1))), max(1, min(4, (int) ($r[1] ?? $r['length'] ?? 1)))];
        }
        ksort($rules);
        $order = (int) ($t->semesterType?->sort_order ?? 1);
        $compulsory = ! empty($d['compulsory_priority'])
            ? ProgrammeCourse::where('semester_order', $order)->where('status', CourseType::COMPULSORY)->distinct()->pluck('course_id')->flip() : collect();
        $source = ! empty($d['copy_from']) ? Timetable::find($d['copy_from'])?->items()->get()->whereNull('group_label')->keyBy('course_id') : collect();
        $changes = collect($d['courses'] ?? []);

        foreach ($t->items()->with('course')->get() as $item) {
            $units = max(1, (int) $item->course?->credit_units);
            $rule = $rules[$units] ?? end($rules) ?: [1, 1];
            $attrs = ['meetings' => $rule[0], 'duration' => $rule[1], 'priority' => $compulsory->has($item->course_id) ? 4 : 3];
            if ($from = $source->get($item->course_id)) {
                $attrs = ['meetings' => $from->meetings, 'duration' => $from->duration, 'activity' => $from->activity, 'venue_type' => $from->venue_type,
                    'facilities' => $from->facilities, 'preferred_venue_id' => $from->preferred_venue_id, 'priority' => $from->priority ?? $attrs['priority']]
                    + ($item->staff ? [] : ['staff' => $from->staff]);
            }
            if ($x = $changes->get($item->course_id) ?? $changes->get((string) $item->course_id)) {
                if (array_key_exists('include', $x) && ! $x['include']) {
                    $attrs['is_active'] = false;
                }
                foreach (['meetings' => [1, 10], 'duration' => [1, 4], 'priority' => [1, 5]] as $k => [$min, $max]) {
                    if (isset($x[$k]) && $x[$k] !== '') {
                        $attrs[$k] = max($min, min($max, (int) $x[$k]));
                    }
                }
                if (! empty($x['activity']) && isset(TimetableItem::ACTIVITIES[$x['activity']]) && $x['activity'] !== 'exam') {
                    $attrs['activity'] = $x['activity'];
                }
                if (array_key_exists('venue_type', $x)) {
                    $attrs['venue_type'] = $x['venue_type'] ?: null;
                }
            }
            $item->update($attrs);
        }
    }

    /**
     * The courses the wizard would schedule, with the meetings, lengths, class sizes and lecturers they would start with,
     * and what is missing or will not fit (no lecturer, nobody registered, no venue large or suitable enough). Nothing
     * is saved.
     *
     * @return array{courses: Collection<int, array>, totals: array}
     */
    public function courses(array $d, User $by): array
    {
        return $this->dryRun(function () use ($d, $by) {
            $t = $this->build($d + ['name' => 'Wizard preview'], $by);
            $p = $this->timetables->problem($t);
            $staff = User::whereIn('id', $t->items->flatMap(fn ($i) => $i->staff ?? [])->unique())->pluck('name', 'id');
            $unitsByItem = collect($p->units)->groupBy('item');
            $rows = $t->items()->with('course.department.faculty')->get()->sortBy(fn ($i) => [$i->course?->department?->name, $i->course?->level, $i->course?->code])
                ->map(function ($i) use ($staff, $p, $unitsByItem) {
                    $warnings = [];
                    $unit = $unitsByItem->get($i->id)?->first();
                    if (! $i->staff) {
                        $warnings[] = 'No lecturer defined for this semester';
                    }
                    if ((int) $i->students === 0) {
                        $warnings[] = 'No students registered yet';
                    }
                    if ($unit) {
                        $suit = $p->suitableVenues($unit);
                        if (! $suit) {
                            $warnings[] = 'No suitable venue (' . ($i->activity === 'practical' ? 'practical' : 'lecture') . ($i->venue_type ? ', ' . $i->venue_type : '') . ')';
                        } elseif (max(array_column($suit, 'cap')) < (int) $i->students) {
                            $warnings[] = 'Largest suitable venue holds ' . max(array_column($suit, 'cap')) . ', class has ' . $i->students;
                        }
                    }

                    return [
                        'course_id' => $i->course_id, 'code' => $i->course?->code, 'title' => $i->course?->title, 'units' => (int) $i->course?->credit_units,
                        'level' => $i->course?->level, 'department' => $i->course?->department?->name ?? '—', 'faculty' => $i->course?->department?->faculty?->name ?? '—',
                        'students' => (int) $i->students, 'meetings' => (int) $i->meetings, 'duration' => (int) $i->duration, 'activity' => $i->activity,
                        'venue_type' => $i->venue_type, 'priority' => (int) $i->priority, 'include' => (bool) $i->is_active,
                        'lecturers' => collect($i->staff ?? [])->map(fn ($id) => $staff[$id] ?? null)->filter()->values()->all(), 'warnings' => $warnings,
                    ];
                })->values();
            $periodsNeeded = (int) $rows->where('include', true)->sum(fn ($r) => $r['meetings'] * $r['duration']);

            return ['courses' => $rows, 'totals' => [
                'courses' => $rows->count(), 'with_warnings' => $rows->filter(fn ($r) => $r['warnings'])->count(), 'periods_needed' => $periodsNeeded,
                'slots' => count($p->slots) - count($p->protected), 'venues' => count($p->venues), 'lecturers' => count($p->staffNames),
                'classes' => count($p->cohortNames), 'venue_slots' => (count($p->slots) - count($p->protected)) * count($p->venues),
                'no_lecturer' => $rows->filter(fn ($r) => ! $r['lecturers'])->count(), 'unavailable_staff' => count($p->staffBusy),
            ]];
        });
    }

    /**
     * Run the whole generation without saving: how much would be placed, per department, what could not be placed and
     * why, and the rule breaks found.
     */
    public function preview(array $d, User $by): array
    {
        return $this->dryRun(function () use ($d, $by) {
            $t = $this->build($d + ['name' => 'Wizard preview'], $by);
            ['problem' => $p, 'solver' => $s] = $this->timetables->run($t, [], $d['algorithm'] ?? 'quick');

            return $this->report($t, $p, $s);
        });
    }

    /**
     * Create the draft timetable and queue the candidates.
     *
     * @param  array<int, array{algorithm: string, seed?: int, label?: string}>  $candidates
     */
    public function create(array $d, User $by, array $candidates): Timetable
    {
        $t = DB::transaction(fn () => $this->build($d, $by));
        $this->runs->queue($t, $by, $candidates);

        return $t->fresh();
    }

    public function report(Timetable $t, \App\Timetabling\Problem $p, Solver $s): array
    {
        $items = $t->items()->with('course.department')->get()->keyBy('id');
        $byDept = [];
        foreach ($p->units as $id => $u) {
            $dept = $items[$u['item']]?->course?->department?->name ?? '—';
            $byDept[$dept] ??= ['department' => $dept, 'units' => 0, 'placed' => 0, 'courses' => []];
            $byDept[$dept]['units']++;
            isset($s->assign[$id]) && $byDept[$dept]['placed']++;
            $byDept[$dept]['courses'][$u['item']] = true;
        }
        $unplaced = [];
        foreach ($s->unplaced as $id => $why) {
            $u = $p->units[$id];
            $unplaced[] = ['code' => $u['code'], 'title' => $u['title'], 'meeting' => $u['meeting'], 'students' => $u['size'],
                'department' => $items[$u['item']]?->course?->department?->name, 'reasons' => $why['reasons'] ?? [], 'suggestions' => $why['suggestions'] ?? []];
        }
        $violations = (new \App\Timetabling\Checker($p))->check($s->assign);
        $perDay = [];
        foreach ($s->assign as $id => $a) {
            $day = $p->slots[$a['slots'][0]]['day'];
            $perDay[$day] = ($perDay[$day] ?? 0) + count($a['slots']);
        }
        ksort($perDay);

        return [
            'units' => count($p->units), 'placed' => count($s->assign), 'unplaced' => count($p->units) - count($s->assign),
            'courses' => $items->where('is_active', true)->count(), 'slots' => count($p->slots), 'venues' => count($p->venues), 'violations' => count($violations),
            'problems' => array_slice(array_column($violations, 'message'), 0, 20), 'score' => round($s->score(), 1),
            'departments' => collect($byDept)->map(fn ($r) => ['department' => $r['department'], 'units' => $r['units'], 'placed' => $r['placed'], 'courses' => count($r['courses'])])->sortBy('department')->values()->all(),
            'unplaced_list' => array_slice($unplaced, 0, 100), 'per_day' => $perDay,
            'periods' => $t->config['periods'] ?? [], 'blocked' => $t->config['blocked'] ?? [],
        ];
    }

    /** Run something against the database and undo every change it made. */
    private function dryRun(callable $fn): mixed
    {
        DB::beginTransaction();
        try {
            return $fn();
        } finally {
            DB::rollBack();
        }
    }
}
