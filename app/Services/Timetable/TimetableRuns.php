<?php

namespace App\Services\Timetable;

use App\Jobs\RunTimetableGeneration;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\TimetableRun;
use App\Models\User;
use App\Services\OperationAudit;
use App\Services\TimetableService;
use App\Timetabling\Analytics;
use App\Timetabling\Problem;
use App\Timetabling\Solver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generation runs (SRS 5.2 steps 6–8): candidates generated with chosen algorithms and seeds, each saved with its
 * configuration, score, validation, unplaced classes and statistics, compared side by side, and one applied to the
 * draft. Runs are queued, so a long search never keeps a browser waiting; with the "sync" queue (and in tests) they run
 * at once. A run can also re-place only part of a timetable (one department, lecturer, venue or set of courses) while
 * everything else stays where it is.
 */
class TimetableRuns
{
    public function __construct(private TimetableService $timetables, private OperationAudit $audit)
    {
    }

    /**
     * Queue candidates. @param  array<int, array{algorithm?: string, seed?: int, label?: string}>  $candidates
     *
     * @return Collection<int, TimetableRun>
     */
    public function queue(Timetable $t, User $by, array $candidates, ?array $scope = null): Collection
    {
        $this->timetables->assertEditable($t, $by);
        if ($t->isExam()) {
            throw new RuntimeException('Candidates are for lecture timetables. Generate an examination timetable from its page.');
        }
        $candidates = array_slice($candidates ?: [['algorithm' => 'quick']], 0, 6);
        $batch = strtoupper(Str::random(6));
        $config = $this->snapshot($t);
        $runs = collect();
        foreach (array_values($candidates) as $i => $c) {
            $n = TimetableRun::where('timetable_id', $t->id)->count() + 1;
            $runs->push(TimetableRun::create([
                'code' => sprintf('RUN-%d-%03d', $t->id, $n), 'timetable_id' => $t->id, 'batch' => $batch,
                'label' => $c['label'] ?? 'Candidate ' . chr(65 + $i), 'algorithm' => isset(Solver::ALGORITHMS[$c['algorithm'] ?? '']) ? $c['algorithm'] : 'quick',
                'seed' => max(1, (int) ($c['seed'] ?? ((int) ($t->config['seed'] ?? 1) + $i * 7919))), 'scope' => $scope, 'config' => $config, 'created_by' => $by->id,
            ]));
        }
        foreach ($runs as $run) {
            RunTimetableGeneration::dispatch($run->id);
        }

        return $runs->map->fresh();
    }

    /** The rules and requirements a run worked from, so it can be documented and compared later. */
    private function snapshot(Timetable $t): array
    {
        return ['rules' => $t->config, 'items' => $t->items()->with('course')->get()->map(fn ($i) => [
            'course' => $i->course?->code, 'activity' => $i->activity, 'group' => $i->group_label, 'students' => $i->students, 'meetings' => $i->meetings,
            'duration' => $i->duration, 'venue_type' => $i->venue_type, 'priority' => $i->priority, 'staff' => $i->staff, 'active' => $i->is_active,
        ])->all()];
    }

    /** Run a queued candidate now. */
    public function execute(TimetableRun $run): TimetableRun
    {
        // Claim it, so a queue worker and the scheduler never run the same candidate twice.
        if (! TimetableRun::whereKey($run->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'error' => null])) {
            return $run->fresh();
        }
        $run->refresh();
        @set_time_limit(900);
        try {
            $t = $run->timetable;
            $c = $t->config;
            $c['seed'] = $run->seed;
            $t->config = $c;   // in memory only: this run's seed
            $p = $this->timetables->problem($t);
            $this->fixOutsideScope($p, $t, $run->scope);
            $s = new Solver($p);
            $s->run($run->algorithm);
            $stats = Analytics::of($p, $s);
            $run->update([
                'status' => 'done', 'assignment' => $s->assign, 'finished_at' => now(), 'duration_ms' => (int) round($s->seconds * 1000),
                'summary' => ['units' => count($p->units), 'placed' => count($s->assign), 'unplaced' => count($p->units) - count($s->assign),
                    'violations' => $stats['hard_violations'], 'soft_missed' => $stats['soft_missed'], 'score' => $stats['score']],
                'stats' => $stats,
                'unplaced' => collect($s->unplaced)->map(fn ($why, $id) => ['unit' => $id, 'code' => $p->units[$id]['code'], 'meeting' => $p->units[$id]['meeting'],
                    'students' => $p->units[$id]['size']] + $why)->values()->all(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => $e->getMessage()]);
        }

        return $run->fresh();
    }

    /** Partial regeneration: everything outside the scope keeps its current place. */
    private function fixOutsideScope(Problem $p, Timetable $t, ?array $scope): void
    {
        if (! $scope) {
            return;
        }
        $items = $t->items()->with('course')->get()->keyBy('id');
        $inside = function (array $u) use ($scope, $items) {
            $course = $items[$u['item']]?->course;

            return (! empty($scope['department_id']) && (int) $course?->department_id === (int) $scope['department_id'])
                || (! empty($scope['user_id']) && in_array((int) $scope['user_id'], $u['staff'], true))
                || (! empty($scope['course_ids']) && in_array((int) $u['course'], array_map('intval', $scope['course_ids']), true))
                || (! empty($scope['level']) && (int) $course?->level === (int) $scope['level']);
        };
        $current = $this->timetables->assignment($t);
        foreach ($current as $unit => $a) {
            if (isset($p->units[$unit]) && ! isset($p->fixed[$unit]) && ! $inside($p->units[$unit])) {
                $p->fixed[$unit] = $a;
            }
        }
        if (! empty($scope['venue_id'])) {
            // re-place what is in the venue, keep everything else
            foreach ($current as $unit => $a) {
                if (isset($a['venues'][(int) $scope['venue_id']])) {
                    unset($p->fixed[$unit]);
                }
            }
        }
    }

    /** Use a finished candidate as the draft's timetable. */
    public function apply(TimetableRun $run, User $by): array
    {
        $t = $run->timetable;
        $this->timetables->assertEditable($t, $by);
        if (! in_array($run->status, ['done', 'applied'], true)) {
            throw new RuntimeException('Only a finished candidate can be used.');
        }
        $p = $this->timetables->problem($t);
        $s = new Solver($p);
        $assign = array_filter($run->assignment ?? [], fn ($a, $unit) => isset($p->units[$unit]), ARRAY_FILTER_USE_BOTH);
        $s->load($assign);
        foreach (array_diff(array_keys($p->units), array_keys($s->assign)) as $unit) {
            $s->unplaced[$unit] = collect($run->unplaced ?? [])->firstWhere('unit', $unit) ?? ['reasons' => ['Not placed in this candidate.'], 'suggestions' => []];
        }
        $s->algorithm = $run->algorithm;
        $s->seconds = round(($run->duration_ms ?? 0) / 1000, 2);
        $summary = $this->timetables->saveAssignment($t, $p, $s, $by, 'candidate_applied', ['run' => $run->code]);
        TimetableRun::where('timetable_id', $t->id)->where('status', 'applied')->update(['status' => 'done']);
        $run->update(['status' => 'applied', 'applied_at' => now(), 'applied_by' => $by->id]);

        return $summary;
    }

    /** Process queued runs (the scheduler calls this every minute when no queue worker is running). */
    public function pending(int $limit = 5): int
    {
        $n = 0;
        foreach (TimetableRun::where('status', 'queued')->orderBy('id')->limit($limit)->get() as $run) {
            $this->execute($run);
            $n++;
        }

        return $n;
    }

    /**
     * What differs between two placements (runs, or a run and the timetable as it stands): classes moved to another time,
     * to another venue, placed in one and not the other, and how the main figures compare.
     *
     * @param  array<string, array>  $a  unit => {slots, venues}
     */
    public static function diff(array $a, array $b, Problem $p): array
    {
        $moved = $venue = $onlyA = $onlyB = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $unit) {
            $code = $p->units[$unit]['code'] ?? $unit;
            $when = fn ($x) => $x ? (\App\Models\Timetable::DAYS[$p->slots[$x['slots'][0]]['day'] ?? 0] ?? '') . ' ' . ($p->slots[$x['slots'][0]]['start'] ?? '') : '—';
            $where = fn ($x) => $x ? collect(array_keys($x['venues']))->map(fn ($v) => $p->venues[$v]['code'] ?? $v)->implode(', ') : '—';
            if (! isset($a[$unit])) {
                $onlyB[] = ['code' => $code, 'b' => $when($b[$unit]) . ' · ' . $where($b[$unit])];
            } elseif (! isset($b[$unit])) {
                $onlyA[] = ['code' => $code, 'a' => $when($a[$unit]) . ' · ' . $where($a[$unit])];
            } elseif ($a[$unit]['slots'] !== $b[$unit]['slots']) {
                $moved[] = ['code' => $code, 'a' => $when($a[$unit]) . ' · ' . $where($a[$unit]), 'b' => $when($b[$unit]) . ' · ' . $where($b[$unit])];
            } elseif (array_keys($a[$unit]['venues']) !== array_keys($b[$unit]['venues'])) {
                $venue[] = ['code' => $code, 'a' => $where($a[$unit]), 'b' => $where($b[$unit])];
            }
        }

        return ['moved' => $moved, 'venue' => $venue, 'only_a' => $onlyA, 'only_b' => $onlyB, 'same' => count(array_intersect_key($a, $b)) - count($moved) - count($venue)];
    }

    /** The timetable's current placement as a run-style assignment. */
    public function current(Timetable $t): array
    {
        return $this->timetables->assignment($t);
    }

    /**
     * Restore an older version: a superseded or earlier timetable's placement copied into this draft (classes that no
     * longer exist are left out, and everything is re-checked).
     */
    public function restoreFrom(Timetable $draft, Timetable $from, User $by): array
    {
        $this->timetables->assertEditable($draft, $by);
        $p = $this->timetables->problem($draft);
        $byItem = $draft->items()->get()->keyBy(fn ($i) => $i->course_id . '|' . $i->activity . '|' . $i->group_label);
        $map = [];
        foreach ($from->items()->get() as $i) {
            if ($mine = $byItem->get($i->course_id . '|' . $i->activity . '|' . $i->group_label)) {
                $map[$i->id] = $mine->id;
            }
        }
        $assign = [];
        foreach (TimetableEntry::where('timetable_id', $from->id)->get()->groupBy('unit_key') as $key => $rows) {
            $first = $rows->first();
            if (! isset($map[$first->timetable_item_id])) {
                continue;
            }
            $unit = preg_replace('/^i\d+/', 'i' . $map[$first->timetable_item_id], $key);
            if (isset($p->units[$unit])) {
                $assign[$unit] = ['slots' => $first->slots, 'venues' => $rows->filter(fn ($r) => $r->venue_id)->mapWithKeys(fn ($r) => [$r->venue_id => (int) $r->seats])->all()];
            }
        }
        $s = new Solver($p);
        $s->load($assign);
        foreach (array_diff(array_keys($p->units), array_keys($s->assign)) as $unit) {
            $s->unplaced[$unit] = ['reasons' => ["Not in {$from->title}."], 'suggestions' => ['Generate again to place it, or place it by hand.']];
        }

        return $this->timetables->saveAssignment($draft, $p, $s, $by, 'version_restored', ['restored_from' => $from->id]);
    }
}
