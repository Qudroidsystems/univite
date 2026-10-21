<?php

namespace App\Services;

use App\Models\CourseAssignment;
use App\Models\CourseRegistration;
use App\Models\Course;
use App\Models\ProgrammeCourse;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\TimetableInvigilator;
use App\Models\TimetableItem;
use App\Models\User;
use App\Models\Venue;
use App\Timetabling\Checker;
use App\Timetabling\InvigilatorPlanner;
use App\Timetabling\Problem;
use App\Timetabling\ProblemBuilder;
use App\Timetabling\Solver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Prepares, generates, checks, adjusts, approves and publishes lecture and examination timetables.
 * The committee sets the rules and what has to be scheduled; the solver proposes a timetable that keeps every hard
 * rule; people review it, move things by hand (each move is checked), and approve and publish it.
 */
class TimetableService
{
    public function __construct(private ProblemBuilder $builder, private OperationAudit $audit, private Messenger $messenger)
    {
    }

    public static function defaultConfig(string $kind): array
    {
        $weights = collect(Timetable::PREFERENCES)->mapWithKeys(fn ($p, $k) => [$k => $p[2]])->all();
        if ($kind === 'exam') {
            return [
                'start_date' => null, 'end_date' => null, 'days' => [1, 2, 3, 4, 5], 'excluded_dates' => [],
                'sittings' => [['09:00', '12:00'], ['13:00', '16:00']], 'blocked' => [], 'scope' => ['departments' => [], 'levels' => []],
                'max_split' => 4, 'invigilator_ratio' => 50, 'min_invigilators' => 2, 'max_duties_per_day' => 2,
                'invigilators' => ['role' => null, 'users' => []], 'weights' => $weights, 'seed' => 1,
            ];
        }
        $periods = [];
        for ($h = 8; $h < 18; $h++) {
            $periods[] = [sprintf('%02d:00', $h), sprintf('%02d:00', $h + 1)];
        }

        return ['days' => [1, 2, 3, 4, 5], 'periods' => $periods, 'blocked' => [], 'scope' => ['departments' => [], 'levels' => []], 'max_consecutive' => 3, 'weights' => $weights, 'seed' => 1];
    }

    public function create(array $data, User $by): Timetable
    {
        return DB::transaction(function () use ($data, $by) {
            $config = self::defaultConfig($data['kind']);
            foreach (['start_date', 'end_date'] as $k) {
                if (! empty($data[$k])) {
                    $config[$k] = $data[$k];
                }
            }
            $config['scope'] = ['departments' => array_map('intval', $data['departments'] ?? []), 'levels' => array_map('intval', $data['levels'] ?? []),
                'programmes' => array_map('intval', $data['programmes'] ?? [])];
            $t = Timetable::create([
                'kind' => $data['kind'], 'name' => $data['name'], 'academic_session_id' => $data['academic_session_id'], 'semester_type_id' => $data['semester_type_id'],
                'config' => $config, 'committee' => array_map('intval', $data['committee'] ?? []) ?: null, 'created_by' => $by->id,
            ]);
            $this->syncItems($t);

            return $t;
        });
    }

    // ---- what has to be scheduled ---------------------------------------------------------------------

    /** Class sizes per course: registrations when students have registered, otherwise the curriculum cohorts. */
    public function classSizes(Timetable $t, array $courseIds): array
    {
        $sizes = CourseRegistration::query()->join('registration_forms as f', 'f.id', '=', 'course_registrations.registration_form_id')
            ->where('f.academic_session_id', $t->academic_session_id)->where('f.semester_type_id', $t->semester_type_id)->where('f.status', '!=', 'rejected')
            ->whereIn('course_registrations.course_id', $courseIds)->groupBy('course_registrations.course_id')
            ->selectRaw('course_registrations.course_id, count(distinct f.student_id) as n')->pluck('n', 'course_id')->all();

        $enrolled = StudentStatus::codesWhere('is_enrolled') ?: ['active'];
        $order = (int) ($t->semesterType?->sort_order ?? 1);
        foreach (ProgrammeCourse::whereIn('course_id', array_diff($courseIds, array_keys($sizes)))->where('semester_order', $order)->where('status', \App\Support\CourseType::COMPULSORY)->get() as $pc) {
            $sizes[$pc->course_id] = ($sizes[$pc->course_id] ?? 0) + Student::where('programme_id', $pc->programme_id)->where('current_level', $pc->level)->whereIn('status', $enrolled)->count();
        }

        return $sizes;
    }

    /** Add an item for every course in scope that has none yet (existing items, and the committee's edits to them, are kept). */
    public function syncItems(Timetable $t, bool $refreshSizes = false): int
    {
        $scope = $t->config['scope'] ?? [];
        $order = (int) ($t->semesterType?->sort_order ?? 1);
        $registered = CourseRegistration::query()->join('registration_forms as f', 'f.id', '=', 'course_registrations.registration_form_id')
            ->where('f.academic_session_id', $t->academic_session_id)->where('f.semester_type_id', $t->semester_type_id)->where('f.status', '!=', 'rejected')
            ->distinct()->pluck('course_registrations.course_id')->all();
        $curriculum = ProgrammeCourse::where('semester_order', $order)->distinct()->pluck('course_id')->all();

        if (! empty($scope['programmes'])) {
            // only what these programmes' students take: their curriculum this semester and what they registered
            $curriculum = ProgrammeCourse::where('semester_order', $order)->whereIn('programme_id', $scope['programmes'])->distinct()->pluck('course_id')->all();
            $registered = CourseRegistration::query()->join('registration_forms as f', 'f.id', '=', 'course_registrations.registration_form_id')
                ->join('students as s', 's.id', '=', 'f.student_id')->whereIn('s.programme_id', $scope['programmes'])
                ->where('f.academic_session_id', $t->academic_session_id)->where('f.semester_type_id', $t->semester_type_id)->where('f.status', '!=', 'rejected')
                ->distinct()->pluck('course_registrations.course_id')->all();
        }
        $courses = Course::whereIn('id', array_unique(array_merge($registered, $curriculum)))->where('is_active', true)
            ->when($scope['departments'] ?? [], fn ($q, $d) => $q->whereIn('department_id', $d))
            ->when($scope['levels'] ?? [], fn ($q, $l) => $q->whereIn('level', $l))->get();
        $sizes = $this->classSizes($t, $courses->pluck('id')->all());
        $staff = CourseAssignment::where('academic_session_id', $t->academic_session_id)->where('semester_type_id', $t->semester_type_id)
            ->whereIn('course_id', $courses->pluck('id'))->get()->groupBy('course_id')->map(fn ($r) => $r->pluck('user_id')->map(fn ($i) => (int) $i)->values()->all());

        $have = $t->items()->get()->groupBy('course_id');
        $added = 0;
        foreach ($courses as $course) {
            $n = (int) ($sizes[$course->id] ?? 0);
            if ($have->has($course->id)) {
                if ($refreshSizes) {
                    $have[$course->id]->whereNull('group_label')->each(fn ($i) => $i->update(['students' => $n]));
                }
                continue;
            }
            if ($t->isExam() && $n === 0) {
                continue;
            }
            $units = max(1, (int) $course->credit_units);
            [$meetings, $duration] = $t->isExam() ? [1, min(180, max(60, $units * 60))] : match (true) {
                $units <= 2 => [1, $units],
                $units === 3 => [3, 1],
                default => [2, 2],
            };
            TimetableItem::create([
                'timetable_id' => $t->id, 'course_id' => $course->id, 'activity' => $t->isExam() ? 'exam' : 'lecture', 'students' => $n,
                'meetings' => $meetings, 'duration' => $duration, 'staff' => $staff[$course->id] ?? [],
            ]);
            $added++;
        }

        return $added;
    }

    // ---- generating -----------------------------------------------------------------------------------

    /** The saved placement, as the solver sees it. */
    public function assignment(Timetable $t): array
    {
        $out = [];
        foreach ($t->entries()->get()->groupBy('unit_key') as $key => $rows) {
            $out[$key] = ['slots' => $rows->first()->slots, 'venues' => $rows->filter(fn ($r) => $r->venue_id)->mapWithKeys(fn ($r) => [$r->venue_id => (int) $r->seats])->all()];
        }

        return $out;
    }

    /** @return array{problem: Problem, solver: Solver} */
    public function run(Timetable $t, array $whatIf = [], string $algorithm = 'quick'): array
    {
        $problem = $this->builder->build($t, $whatIf);
        $solver = new Solver($problem);
        $solver->run($algorithm);

        return ['problem' => $problem, 'solver' => $solver];
    }

    public function generate(Timetable $t, User $by, string $algorithm = 'quick'): array
    {
        $this->editable($t, $by);
        @set_time_limit(600);
        ['problem' => $p, 'solver' => $s] = $this->run($t, [], $algorithm);

        return $this->saveAssignment($t, $p, $s, $by);
    }

    /**
     * Replace the timetable's unlocked entries with the solver's placement, note why anything could not be placed,
     * and keep the summary and analytics. Used by generating, by applying a saved candidate and by partial regeneration.
     */
    public function saveAssignment(Timetable $t, Problem $p, Solver $s, User $by, string $action = 'generated', array $note = []): array
    {
        return DB::transaction(function () use ($t, $by, $p, $s, $action, $note) {
            $t->entries()->where('locked', false)->delete();
            $locked = $t->entries()->where('locked', true)->pluck('unit_key')->unique()->all();
            foreach ($s->assign as $unit => $a) {
                if (! in_array($unit, $locked, true) && isset($p->units[$unit])) {
                    $this->saveUnit($t, $p, $unit, $a, false);
                }
            }
            $byItem = [];
            foreach ($s->unplaced as $unit => $why) {
                $byItem[$p->units[$unit]['item']][] = $why + ['meeting' => $p->units[$unit]['meeting']];
            }
            foreach ($t->items as $item) {
                $item->update(['issues' => $byItem[$item->id] ?? null]);
            }

            $summary = $this->summarise($t->fresh(), $p, $s) + $note;
            if ($t->isExam()) {
                $summary['invigilation'] = (new InvigilatorPlanner)->plan($t, $p, $s->assign);
            } else {
                $summary['analytics'] = \App\Timetabling\Analytics::of($p, $s);
            }
            $t->update(['summary' => $summary, 'generated_at' => now(), 'generated_by' => $by->id]);

            $op = $this->audit->begin('timetable_generate', 'timetables', $by, $t->academic_session_id, null, ['timetable' => $t->id, 'version' => $t->version] + $note);
            $this->audit->record($op, $action, $t, null, ['placed' => $summary['placed'], 'unplaced' => $summary['unplaced'], 'violations' => $summary['violations']], null, null, $t->title);
            $this->audit->finish($op, $summary['placed'], 0, $summary['unplaced']);

            return $summary;
        });
    }

    /** Throws unless this person may change the timetable now. */
    public function assertEditable(Timetable $t, User $by): void
    {
        $this->editable($t, $by);
    }

    /** The solver's model of the timetable (for runs and analytics). */
    public function problem(Timetable $t, array $whatIf = []): Problem
    {
        return $this->builder->build($t, $whatIf);
    }

    private function summarise(Timetable $t, Problem $p, Solver $s): array
    {
        $violations = (new Checker($p))->check($s->assign);

        return [
            'units' => count($p->units), 'placed' => count($s->assign), 'unplaced' => count($p->units) - count($s->assign),
            'violations' => count($violations), 'problems' => array_slice(array_column($violations, 'message'), 0, 50),
            'score' => $s->score(), 'slots' => count($p->slots), 'venues' => count($p->venues), 'checked_at' => now()->toDateTimeString(),
            'algorithm' => $s->algorithm, 'seconds' => $s->seconds,
        ];
    }

    private function saveUnit(Timetable $t, Problem $p, string $unit, array $a, bool $locked): void
    {
        $u = $p->units[$unit];
        $first = $p->slots[$a['slots'][0]];
        $last = $p->slots[end($a['slots'])];
        $ends = $p->isExam() && $u['minutes'] ? Carbon::parse($first['start'])->addMinutes($u['minutes'])->format('H:i') : $last['end'];
        $rows = $a['venues'] ?: [null => $u['size']];
        foreach ($rows as $vid => $seats) {
            TimetableEntry::create([
                'timetable_id' => $t->id, 'timetable_item_id' => $u['item'], 'course_id' => $u['course'], 'unit_key' => $unit,
                'day' => $first['day'], 'date' => $first['date'], 'starts_at' => $first['start'], 'ends_at' => $ends, 'slots' => $a['slots'],
                'venue_id' => $vid ?: null, 'seats' => (int) $seats, 'locked' => $locked,
            ]);
        }
    }

    /** Re-check the saved timetable against today's rules, venues and availability. */
    public function check(Timetable $t): array
    {
        $p = $this->builder->build($t);
        $assign = $this->assignment($t);
        $s = new Solver($p);
        $s->load($assign);
        $violations = (new Checker($p))->check($assign);
        $unplaced = array_diff(array_keys($p->units), array_keys($assign));

        return ['problem' => $p, 'solver' => $s, 'violations' => $violations, 'unplaced' => array_values($unplaced)];
    }

    public function refreshSummary(Timetable $t): array
    {
        ['problem' => $p, 'solver' => $s, 'violations' => $v] = $this->check($t);
        $before = $t->summary ?? [];
        $s->algorithm = $before['algorithm'] ?? 'quick';
        $s->seconds = (float) ($before['seconds'] ?? 0);
        $summary = array_merge($before, $this->summarise($t, $p, $s));
        if (! $t->isExam()) {
            // statistics follow every change to the draft
            $summary['analytics'] = \App\Timetabling\Analytics::of($p, $s, ['manual_changes' => \App\Models\OperationEvent::whereHas('batch', fn ($q) => $q->where('type', 'timetable_move'))
                ->where('subject_type', class_basename(Timetable::class))->where('subject_id', $t->id)->count()]);
        }
        $t->update(['summary' => $summary]);

        return $summary;
    }

    // ---- moving things by hand ------------------------------------------------------------------------

    public function alternatives(Timetable $t, string $unit): array
    {
        ['problem' => $p, 'solver' => $s] = $this->check($t);
        if (! isset($p->units[$unit])) {
            return [];
        }

        return array_map(fn ($o) => $o + ['label' => $this->describe($p, $o)], $s->alternatives($unit));
    }

    public function describe(Problem $p, array $a): string
    {
        $first = $p->slots[$a['slots'][0]];
        $last = $p->slots[end($a['slots'])];
        $day = $first['date'] ? Carbon::parse($first['date'])->format('D j M') : (Timetable::DAYS[$first['day']] ?? '');
        $venues = implode(' + ', array_map(fn ($v, $n) => ($p->venues[$v]['code'] ?? '?') . (count($a['venues']) > 1 ? " ({$n})" : ''), array_keys($a['venues']), $a['venues']));

        return "{$day} {$first['start']}–{$last['end']}" . ($venues ? " · {$venues}" : '');
    }

    /**
     * Move a placement to another time and venue(s). Refused with the reasons if it would break any hard rule,
     * including rules already broken elsewhere that this move would make worse. Moved entries are locked.
     */
    public function move(Timetable $t, string $unit, array $slots, array $venues, User $by, ?string $reason = null): void
    {
        $this->editable($t, $by);
        ['problem' => $p, 'solver' => $s] = $this->check($t);
        if (! isset($p->units[$unit])) {
            throw new RuntimeException('That class or paper is not part of this timetable.');
        }
        $before = $s->assign[$unit] ?? null;
        $assign = $s->assign;
        $assign[$unit] = ['slots' => array_values($slots), 'venues' => array_map('intval', $venues)];
        $checker = new Checker($p);
        $old = array_column($checker->check($s->assign), 'message');
        $new = array_filter($checker->check($assign), fn ($v) => in_array($unit, $v['units'], true) && ! in_array($v['message'], $old, true));
        if ($new) {
            throw new RuntimeException('This move would break a hard rule: ' . implode(' ', array_column($new, 'message')));
        }

        DB::transaction(function () use ($t, $p, $unit, $assign, $before, $by, $reason) {
            $t->entries()->where('unit_key', $unit)->delete();
            $this->saveUnit($t, $p, $unit, $assign[$unit], true);
            $op = $this->audit->begin('timetable_move', 'timetables', $by, $t->academic_session_id, $reason, ['timetable' => $t->id]);
            $this->audit->record($op, 'moved', $t, $before ? ['when' => $this->describe($p, $before)] : null, ['when' => $this->describe($p, $assign[$unit])], $reason, null, $p->units[$unit]['code'] . ' · ' . $t->title);
            $this->audit->finish($op, 1);
        });
        $this->refreshSummary($t->fresh());
        if ($t->isExam()) {
            $this->replanInvigilators($t->fresh());
        }
    }

    public function unlock(Timetable $t, string $unit, User $by): void
    {
        $this->editable($t, $by);
        $t->entries()->where('unit_key', $unit)->update(['locked' => false]);
    }

    public function replanInvigilators(Timetable $t): array
    {
        ['problem' => $p, 'solver' => $s] = $this->check($t);
        $plan = (new InvigilatorPlanner)->plan($t, $p, $s->assign);
        $t->update(['summary' => array_merge($t->summary ?? [], ['invigilation' => $plan])]);

        return $plan;
    }

    // ---- what-if --------------------------------------------------------------------------------------

    public function whatIf(Timetable $t, array $whatIf): array
    {
        ['problem' => $p, 'solver' => $s] = $this->run($t, $whatIf);
        $base = $t->summary ?? [];

        return [
            'before' => ['placed' => $base['placed'] ?? null, 'unplaced' => $base['unplaced'] ?? null, 'violations' => $base['violations'] ?? null, 'score' => $base['score'] ?? null],
            'after' => ['placed' => count($s->assign), 'unplaced' => count($s->unplaced), 'violations' => count((new Checker($p))->check($s->assign)), 'score' => $s->score()],
            'unplaced' => collect($s->unplaced)->map(fn ($why, $unit) => ['code' => $p->units[$unit]['code'], 'why' => $why])->values()->all(),
            'slots' => count($p->slots),
        ];
    }

    // ---- approval and publication ---------------------------------------------------------------------

    public function submit(Timetable $t, User $by): void
    {
        $this->editable($t, $by);
        $summary = $this->refreshSummary($t);
        if (! $t->generated_at && ! $t->entries()->exists()) {
            throw new RuntimeException('Generate the timetable before sending it for approval.');
        }
        if ($summary['violations'] > 0) {
            throw new RuntimeException("It breaks {$summary['violations']} hard rule(s). Fix them before sending it for approval.");
        }
        $t->update(['status' => 'review', 'submitted_at' => now(), 'submitted_by' => $by->id]);
        $this->audit->note('timetable_submit', 'timetables', $by, 'submitted', $t, ['status' => 'draft'], ['status' => 'review'], null, $t->academic_session_id);
        foreach (User::permission('Approve timetables')->get() as $approver) {
            $this->messenger->notify($approver, 'timetable', 'Timetable waiting for approval', "{$t->title} has been sent for approval by {$by->name}.", $t, route('timetables.show', $t), 'info', null, ['portal']);
        }
    }

    public function approve(Timetable $t, User $by, ?string $note = null): void
    {
        if ($t->status !== 'review') {
            throw new RuntimeException('Only a timetable sent for approval can be approved.');
        }
        $summary = $this->refreshSummary($t);
        if ($summary['violations'] > 0) {
            throw new RuntimeException("Since it was sent, things changed and it now breaks {$summary['violations']} hard rule(s). Return it to the committee.");
        }
        $t->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $by->id, 'decision_note' => $note]);
        $this->audit->note('timetable_approve', 'timetables', $by, 'approved', $t, ['status' => 'review'], ['status' => 'approved'], $note, $t->academic_session_id);
    }

    public function returnToCommittee(Timetable $t, User $by, string $note): void
    {
        if (! in_array($t->status, ['review', 'approved'], true)) {
            throw new RuntimeException('Only a timetable awaiting approval or publication can be returned.');
        }
        $from = $t->status;
        $t->update(['status' => 'draft', 'decision_note' => $note, 'approved_at' => null, 'approved_by' => null]);
        $this->audit->note('timetable_return', 'timetables', $by, 'returned', $t, ['status' => $from], ['status' => 'draft'], $note, $t->academic_session_id);
        foreach (User::whereIn('id', array_filter([$t->submitted_by, $t->created_by, ...($t->committee ?? [])]))->get() as $u) {
            $this->messenger->notify($u, 'timetable', 'Timetable returned', "{$t->title} was returned by {$by->name}: {$note}", $t, route('timetables.show', $t), 'warning', null, ['portal']);
        }
    }

    public function publish(Timetable $t, User $by): int
    {
        if ($t->status !== 'approved') {
            throw new RuntimeException('Only an approved timetable can be published.');
        }
        $summary = $this->refreshSummary($t);
        if ($summary['violations'] > 0) {
            throw new RuntimeException("It now breaks {$summary['violations']} hard rule(s) (a venue may have closed or someone gone on leave). Return it to the committee.");
        }

        return DB::transaction(function () use ($t, $by) {
            $replaced = Timetable::where(['kind' => $t->kind, 'academic_session_id' => $t->academic_session_id, 'semester_type_id' => $t->semester_type_id, 'status' => 'published'])
                ->where('id', '!=', $t->id)->get()->filter(fn ($o) => $o->id === $t->previous_id || $this->sameScope($o, $t));
            foreach ($replaced as $old) {
                $old->update(['status' => 'superseded']);
            }
            $t->update(['status' => 'published', 'published_at' => now(), 'published_by' => $by->id]);
            $this->audit->note('timetable_publish', 'timetables', $by, 'published', $t, ['status' => 'approved', 'replaces' => $replaced->pluck('title')->implode(', ') ?: null], ['status' => 'published'], null, $t->academic_session_id);

            return $this->announce($t, $replaced->isNotEmpty());
        });
    }

    private function sameScope(Timetable $a, Timetable $b): bool
    {
        $norm = fn ($t) => [collect($t->config['scope']['departments'] ?? [])->sort()->values()->all(), collect($t->config['scope']['levels'] ?? [])->sort()->values()->all()];

        return $norm($a) === $norm($b);
    }

    /** Tell the students taking the courses and the staff teaching or invigilating them. */
    private function announce(Timetable $t, bool $revised): int
    {
        $courseIds = $t->entries()->distinct()->pluck('course_id')->all();
        $studentUsers = Student::whereIn('id', CourseRegistration::query()->join('registration_forms as f', 'f.id', '=', 'course_registrations.registration_form_id')
            ->where('f.academic_session_id', $t->academic_session_id)->where('f.semester_type_id', $t->semester_type_id)->where('f.status', '!=', 'rejected')
            ->whereIn('course_registrations.course_id', $courseIds)->distinct()->pluck('f.student_id'))->pluck('user_id')->filter()->unique();
        $staff = $t->items()->pluck('staff')->flatten()->merge($t->invigilators()->pluck('user_id'))->filter()->unique();
        $what = $t->isExam() ? 'examination timetable' : 'lecture timetable';
        $title = $revised ? "Revised {$what} published" : ucfirst($what) . ' published';
        $n = 0;
        foreach (User::whereIn('id', $studentUsers->merge($staff)->unique())->get() as $u) {
            $route = $u->student ? ($t->isExam() ? route('my.exams') : route('my.timetable')) : route('my.teaching');
            $this->messenger->notify($u, 'timetable', $title, "The {$t->session?->name} {$what} ({$t->name}) is now published. Open the portal to see your " . ($t->isExam() ? 'papers' : 'classes') . '.', $t, $route, 'info', "tt{$t->id}-v{$t->version}-u{$u->id}");
            $n++;
        }

        return $n;
    }

    /** Start a new version of a published timetable. The published one stays in force until the new one is published. */
    public function revise(Timetable $t, User $by): Timetable
    {
        if ($t->status !== 'published') {
            throw new RuntimeException('Only a published timetable is revised. Edit a draft directly.');
        }
        if ($open = Timetable::where('previous_id', $t->id)->whereIn('status', ['draft', 'review', 'approved'])->first()) {
            throw new RuntimeException("A revision is already being prepared ({$open->title}).");
        }

        return DB::transaction(function () use ($t, $by) {
            $new = $t->replicate(['status', 'summary', 'generated_at', 'generated_by', 'submitted_at', 'submitted_by', 'approved_at', 'approved_by', 'decision_note', 'published_at', 'published_by']);
            $new->fill(['version' => $t->version + 1, 'previous_id' => $t->id, 'status' => 'draft', 'created_by' => $by->id])->save();
            $map = [];
            foreach ($t->items as $item) {
                $copy = $item->replicate();
                $copy->timetable_id = $new->id;
                $copy->save();
                $map[$item->id] = $copy->id;
            }
            foreach ($t->entries as $e) {
                $copy = $e->replicate();
                $copy->fill(['timetable_id' => $new->id, 'timetable_item_id' => $map[$e->timetable_item_id], 'unit_key' => preg_replace('/^i\d+/', 'i' . $map[$e->timetable_item_id], $e->unit_key)])->save();
            }
            foreach ($t->invigilators as $d) {
                $copy = $d->replicate();
                $copy->timetable_id = $new->id;
                $copy->save();
            }
            $new->update(['generated_at' => $t->generated_at, 'summary' => $t->summary]);
            $this->audit->note('timetable_revise', 'timetables', $by, 'revision started', $new, ['version' => $t->version], ['version' => $new->version], null, $t->academic_session_id);

            return $new;
        });
    }

    private function editable(Timetable $t, User $by): void
    {
        if (! $t->isEditable()) {
            throw new RuntimeException('Only a timetable that is being prepared can be changed. ' . ($t->status === 'published' ? 'Start a revision instead.' : 'Return it to the committee first.'));
        }
        if (! $t->preparedBy($by)) {
            throw new RuntimeException('Only the members of this timetable\'s committee can change it.');
        }
    }

    // ---- what a closure or someone's leave affects ----------------------------------------------------

    /** Entries in live timetables that use a venue while it is closed, with the best replacement for each. */
    public function venueImpact(Venue $venue, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $entries = TimetableEntry::with('timetable', 'item.course')->where('venue_id', $venue->id)
            ->whereHas('timetable', fn ($q) => $q->whereIn('status', ['draft', 'review', 'approved', 'published']))
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->whereNull('date')->orWhere(fn ($d) => $d->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', ($to ?? $from)->toDateString()))))
            ->get();

        $out = collect();
        foreach ($entries->groupBy('timetable_id') as $rows) {
            $t = $rows->first()->timetable;
            $check = null;
            foreach ($rows as $e) {
                $check ??= $this->check($t);
                $alts = array_slice(array_values(array_filter($check['solver']->alternatives($e->unit_key, 30), fn ($o) => $o['slots'] === $e->slots)), 0, 3);
                $out->push(['entry' => $e, 'timetable' => $t, 'options' => array_map(fn ($o) => $this->describe($check['problem'], $o), $alts)]);
            }
        }

        return $out;
    }

    /** A member of staff's classes and invigilation duties while they are away. */
    public function staffImpact(User $user, Carbon $from, Carbon $to): array
    {
        $teaching = TimetableEntry::with('timetable', 'item.course', 'venue')->whereHas('timetable', fn ($q) => $q->where('status', 'published'))
            ->whereHas('item', fn ($q) => $q->whereJsonContains('staff', $user->id))->get()
            ->filter(fn ($e) => $e->date ? $e->date->between($from, $to) : true)
            ->unique('unit_key')->values();
        $duties = TimetableInvigilator::with('venue', 'timetable')->where('user_id', $user->id)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->whereHas('timetable', fn ($q) => $q->where('status', 'published'))->get();

        return ['teaching' => $teaching, 'duties' => $duties];
    }
}
