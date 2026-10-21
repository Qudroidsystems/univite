<?php

namespace App\Http\Controllers\Timetable;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Timetable;
use App\Models\TimetableRun;
use App\Models\User;
use App\Models\Venue;
use App\Services\Timetable\TimetableRuns;
use App\Services\TimetableService;
use App\Timetabling\Solver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** Candidates of a timetable: generate, compare, apply; partial regeneration; analytics; versions compared and restored. */
class TimetableRunController extends Controller
{
    public function __construct(private TimetableRuns $runs, private TimetableService $timetables)
    {
        $this->middleware('permission:View timetables|Create timetables')->only('index', 'status', 'show', 'compare', 'analytics', 'versions');
        $this->middleware('permission:Create timetables')->only('store', 'apply', 'runNow', 'restore', 'refresh');
    }

    public function index(Request $request, Timetable $timetable): View
    {
        $runs = $timetable->runs()->with('creator')->get();

        return view('timetables.runs', [
            'pagetitle' => 'Candidates · ' . $timetable->title, 't' => $timetable->load('session', 'semesterType'), 'runs' => $runs,
            'algorithms' => Solver::ALGORITHMS, 'canPrepare' => $timetable->preparedBy($request->user()) && $timetable->isEditable(),
            'departments' => Department::whereIn('id', $timetable->items()->join('courses', 'courses.id', '=', 'timetable_items.course_id')->pluck('courses.department_id'))->orderBy('name')->pluck('name', 'id'),
            'lecturers' => User::whereIn('id', $timetable->items->flatMap(fn ($i) => $i->staff ?? [])->unique())->orderBy('name')->pluck('name', 'id'),
            'venues' => Venue::whereIn('id', $timetable->entries()->whereNotNull('venue_id')->distinct()->pluck('venue_id'))->orderBy('code')->pluck('code', 'id'),
            'levels' => $timetable->items()->join('courses', 'courses.id', '=', 'timetable_items.course_id')->distinct()->orderBy('courses.level')->pluck('courses.level')->filter(),
            'pending' => $runs->whereIn('status', ['queued', 'running'])->count(),
        ]);
    }

    /** For the page to follow runs still in the queue. */
    public function status(Timetable $timetable): JsonResponse
    {
        return response()->json(['runs' => $timetable->runs()->get(['id', 'code', 'status', 'summary'])->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'status' => $r->status, 'label' => TimetableRun::STATUSES[$r->status]])]);
    }

    /** More candidates, or a partial regeneration (one department, lecturer, venue or level). */
    public function store(Request $request, Timetable $timetable): RedirectResponse
    {
        $d = $request->validate([
            'algorithm' => ['required', Rule::in(array_keys(Solver::ALGORITHMS))], 'count' => ['nullable', 'integer', 'between:1,5'], 'seed' => ['nullable', 'integer', 'between:1,99999'],
            'department_id' => ['nullable', 'exists:departments,id'], 'user_id' => ['nullable', 'exists:users,id'], 'venue_id' => ['nullable', 'exists:venues,id'], 'level' => ['nullable', 'integer'],
        ]);
        $scope = array_filter(['department_id' => $d['department_id'] ?? null, 'user_id' => $d['user_id'] ?? null, 'venue_id' => $d['venue_id'] ?? null, 'level' => $d['level'] ?? null]) ?: null;
        $base = (int) ($d['seed'] ?? random_int(1, 90000));
        $candidates = [];
        for ($i = 0; $i < (int) ($d['count'] ?? 1); $i++) {
            $candidates[] = ['algorithm' => $d['algorithm'], 'seed' => $base + $i * 7919, 'label' => ($scope ? 'Partial ' : 'Candidate ') . chr(65 + $i)];
        }
        try {
            $this->runs->queue($timetable, $request->user(), $candidates, $scope);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', count($candidates) . ' candidate(s) queued.' . ($scope ? ' Only the chosen part is re-placed; everything else stays where it is.' : ''));
    }

    /** Generate the waiting candidates in this browser window (when no queue worker is running). */
    public function runNow(Timetable $timetable): RedirectResponse
    {
        @set_time_limit(900);
        foreach ($timetable->runs()->where('status', 'queued')->get() as $run) {
            $this->runs->execute($run);
        }

        return back()->with('success', 'Waiting candidates generated.');
    }

    public function show(Timetable $timetable, TimetableRun $run): View
    {
        abort_unless($run->timetable_id === $timetable->id, 404);
        $p = $this->timetables->problem($timetable);
        $diff = $run->assignment ? TimetableRuns::diff($this->runs->current($timetable), $run->assignment, $p) : null;

        return view('timetables.run', ['pagetitle' => $run->code, 't' => $timetable, 'run' => $run->load('creator'), 'diff' => $diff,
            'canPrepare' => $timetable->preparedBy(request()->user()) && $timetable->isEditable()]);
    }

    public function compare(Request $request, Timetable $timetable): View
    {
        $ids = array_slice(array_map('intval', (array) $request->query('runs', [])), 0, 4);
        $runs = TimetableRun::where('timetable_id', $timetable->id)->whereIn('id', $ids)->whereNotNull('assignment')->get()->sortBy('id')->values();
        abort_if($runs->count() < 2, 422, 'Choose two to four finished candidates to compare.');
        $p = $this->timetables->problem($timetable);

        return view('timetables.compare', ['pagetitle' => 'Compare candidates', 't' => $timetable, 'runs' => $runs, 'diff' => TimetableRuns::diff($runs[0]->assignment, $runs[1]->assignment, $p)]);
    }

    public function apply(Request $request, Timetable $timetable, TimetableRun $run): RedirectResponse
    {
        abort_unless($run->timetable_id === $timetable->id, 404);
        try {
            $s = $this->runs->apply($run, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('timetables.show', $timetable)->with('success', "{$run->label} is now the draft: {$s['placed']} of {$s['units']} sessions placed, {$s['violations']} hard rule break(s). Review it, then send it for approval.");
    }

    public function analytics(Request $request, Timetable $timetable): View
    {
        $a = $timetable->summary['analytics'] ?? null;

        return view('timetables.analytics', ['pagetitle' => 'Analytics · ' . $timetable->title, 't' => $timetable, 'a' => $a,
            'canPrepare' => $timetable->preparedBy($request->user())]);
    }

    public function refresh(Timetable $timetable): RedirectResponse
    {
        $this->timetables->refreshSummary($timetable);

        return back()->with('success', 'Statistics recalculated from the timetable as it stands.');
    }

    /** Versions of this timetable (and earlier ones of the same semester) compared with this one. */
    public function versions(Request $request, Timetable $timetable): View
    {
        $others = Timetable::where('kind', $timetable->kind)->where('academic_session_id', $timetable->academic_session_id)->where('semester_type_id', $timetable->semester_type_id)
            ->whereKeyNot($timetable->id)->latest('id')->get();
        $other = $request->filled('with') ? $others->firstWhere('id', (int) $request->query('with')) : $others->first();
        $diff = null;
        if ($other) {
            $p = $this->timetables->problem($timetable);
            $mine = $this->runs->current($timetable);
            // match the other version's classes to this one's by course, so different item ids still line up
            $theirs = [];
            $items = $timetable->items()->get()->keyBy(fn ($i) => $i->course_id . '|' . $i->activity . '|' . $i->group_label);
            foreach ($other->entries()->with('item')->get()->groupBy('unit_key') as $key => $rows) {
                $mineItem = $items->get($rows->first()->course_id . '|' . $rows->first()->item?->activity . '|' . $rows->first()->item?->group_label);
                if ($mineItem) {
                    $unit = preg_replace('/^i\d+/', 'i' . $mineItem->id, $key);
                    $theirs[$unit] = ['slots' => $rows->first()->slots, 'venues' => $rows->filter(fn ($r) => $r->venue_id)->mapWithKeys(fn ($r) => [$r->venue_id => (int) $r->seats])->all()];
                }
            }
            $diff = TimetableRuns::diff($theirs, $mine, $p);
        }

        return view('timetables.versions', ['pagetitle' => 'Versions · ' . $timetable->title, 't' => $timetable, 'others' => $others, 'other' => $other, 'diff' => $diff,
            'canPrepare' => $timetable->preparedBy($request->user()) && $timetable->isEditable()]);
    }

    public function restore(Request $request, Timetable $timetable, Timetable $from): RedirectResponse
    {
        try {
            $s = $this->runs->restoreFrom($timetable, $from, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('timetables.show', $timetable)->with('success', "Placement restored from {$from->title}: {$s['placed']} of {$s['units']} sessions, {$s['violations']} hard rule break(s).");
    }
}
