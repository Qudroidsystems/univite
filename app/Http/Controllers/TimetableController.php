<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\SemesterType;
use App\Models\Timetable;
use App\Models\TimetableItem;
use App\Models\User;
use App\Models\Venue;
use App\Services\TimetableService;
use App\Support\Lookups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Spatie\Permission\Models\Role;

/** Committee screens for lecture and examination timetables. */
class TimetableController extends Controller
{
    public function __construct(private TimetableService $svc)
    {
        $this->middleware('permission:View timetables|Create timetables')->only('index', 'show', 'entry', 'invigilators', 'print');
        $this->middleware('permission:Create timetables')->only('create', 'store', 'settings', 'updateSettings', 'items', 'updateItem', 'addItem', 'syncItems', 'generate', 'move', 'unlock', 'whatIf', 'replan', 'submit', 'destroy');
        $this->middleware('permission:Approve timetables')->only('approve', 'returnIt');
        $this->middleware('permission:Publish timetables')->only('publish', 'revise');
    }

    public function index(Request $request): View
    {
        $q = Timetable::with('session', 'semesterType', 'creator')->when($request->filled('kind'), fn ($w) => $w->where('kind', $request->kind))
            ->when($request->filled('session'), fn ($w) => $w->where('academic_session_id', $request->session))->latest('id');

        return view('timetables.index', [
            'pagetitle' => 'Timetables', 'timetables' => $q->paginate(25)->withQueryString(),
            'sessions' => AcademicSession::orderByDesc('id')->pluck('name', 'id'),
        ]);
    }

    public function create(): View
    {
        $current = AcademicSession::current();

        return view('timetables.create', [
            'pagetitle' => 'New timetable', 'sessions' => AcademicSession::orderByDesc('id')->pluck('name', 'id'), 'current' => $current,
            'semesterTypes' => SemesterType::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'),
            'departments' => Department::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'levels' => Course::distinct()->orderBy('level')->pluck('level'),
            'staff' => User::whereDoesntHave('student')->where('is_disabled', false)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Timetable::KINDS))], 'name' => ['required', 'string', 'max:150'],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'], 'semester_type_id' => ['required', 'exists:semester_types,id'],
            'start_date' => ['required_if:kind,exam', 'nullable', 'date'], 'end_date' => ['required_if:kind,exam', 'nullable', 'date', 'after_or_equal:start_date'],
            'departments' => ['nullable', 'array'], 'departments.*' => ['exists:departments,id'], 'levels' => ['nullable', 'array'], 'levels.*' => ['integer'],
            'committee' => ['nullable', 'array'], 'committee.*' => ['exists:users,id'],
        ]);
        $t = $this->svc->create($d, $request->user());

        return redirect()->route('timetables.items', $t)->with('success', "Timetable created with {$t->items()->count()} course(s) to schedule. Check class sizes, lecturers and needs below, then set the rules and generate.");
    }

    public function show(Request $request, Timetable $timetable): View
    {
        $t = $timetable->load('session', 'semesterType', 'creator', 'approver', 'publisher', 'previous');
        $entries = $t->entries()->with('course', 'venue', 'item')->get();
        $levels = $entries->pluck('course.level')->filter()->unique()->sort()->values();
        if ($request->filled('level')) {
            $entries = $entries->where('course.level', (int) $request->level);
        }
        if ($request->filled('venue')) {
            $entries = $entries->where('venue_id', (int) $request->venue);
        }

        return view('timetables.show', [
            'pagetitle' => $t->title, 't' => $t, 'entries' => $entries, 'levels' => $levels,
            'unplaced' => $t->items()->with('course')->whereNotNull('issues')->get(),
            'canPrepare' => $t->preparedBy($request->user()), 'grid' => $this->grid($t),
            'venues' => Venue::whereIn('id', $t->entries()->whereNotNull('venue_id')->distinct()->pluck('venue_id'))->orderBy('code')->pluck('code', 'id'),
            'revision' => Timetable::where('previous_id', $t->id)->latest('id')->first(),
            'seating' => $t->isExam() ? $t->seatingPlans()->latest('id')->first() : null,
        ]);
    }

    /** Rows and columns of the timetable grid: [rowKey => label], [colKey => label]. */
    private function grid(Timetable $t): array
    {
        $c = $t->config ?? [];
        if (! $t->isExam()) {
            return [
                'rows' => collect($c['days'] ?? [])->mapWithKeys(fn ($d) => [$d => Timetable::DAYS[$d] ?? $d])->all(),
                'cols' => collect($c['periods'] ?? [])->mapWithKeys(fn ($p) => [$p[0] => $p[0] . '–' . $p[1]])->all(),
            ];
        }
        $dates = $t->entries()->whereNotNull('date')->distinct()->orderBy('date')->pluck('date')->map(fn ($d) => \Carbon\Carbon::parse($d)->toDateString())->unique()->values();

        return [
            'rows' => $dates->mapWithKeys(fn ($d) => [$d => \Carbon\Carbon::parse($d)->format('D j M')])->all(),
            'cols' => collect($c['sittings'] ?? [])->mapWithKeys(fn ($s) => [$s[0] => $s[0] . '–' . $s[1]])->all(),
        ];
    }

    public function print(Timetable $timetable): View
    {
        return view('timetables.print', ['t' => $timetable->load('session', 'semesterType'), 'entries' => $timetable->entries()->with('course', 'venue', 'item')->get(), 'grid' => $this->grid($timetable)]);
    }

    // ---- settings -------------------------------------------------------------------------------------

    public function settings(Timetable $timetable): View
    {
        return view('timetables.settings', [
            'pagetitle' => 'Rules · ' . $timetable->title, 't' => $timetable, 'c' => $timetable->config,
            'staff' => User::whereDoesntHave('student')->where('is_disabled', false)->orderBy('name')->pluck('name', 'id'),
            'roles' => Role::orderBy('name')->pluck('name'),
            'departments' => Department::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'levels' => Course::distinct()->orderBy('level')->pluck('level'),
        ]);
    }

    /** "08:00-09:00, 09:00-10:00" -> [["08:00","09:00"], ...] */
    private static function ranges(?string $text): array
    {
        preg_match_all('/(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})/', (string) $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) {
            $a = sprintf('%02d:%02d', $x[1], $x[2]);
            $b = sprintf('%02d:%02d', $x[3], $x[4]);
            $a < $b && $out[] = [$a, $b];
        }
        usort($out, fn ($p, $q) => $p[0] <=> $q[0]);

        return $out;
    }

    public function updateSettings(Request $request, Timetable $timetable): RedirectResponse
    {
        if (! $timetable->isEditable() || ! $timetable->preparedBy($request->user())) {
            return back()->with('error', 'Only a timetable being prepared can be changed, by its committee.');
        }
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'days' => ['required', 'array', 'min:1'], 'days.*' => ['integer', 'between:1,7'],
            'periods' => ['nullable', 'string', 'max:2000'], 'sittings' => ['nullable', 'string', 'max:500'], 'blocked' => ['nullable', 'string', 'max:3000'],
            'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'excluded_dates' => ['nullable', 'string', 'max:2000'],
            'max_consecutive' => ['nullable', 'integer', 'between:1,10'], 'max_split' => ['nullable', 'integer', 'between:1,10'],
            'invigilator_ratio' => ['nullable', 'integer', 'between:5,500'], 'min_invigilators' => ['nullable', 'integer', 'between:1,10'], 'max_duties_per_day' => ['nullable', 'integer', 'between:1,4'],
            'invigilator_role' => ['nullable', 'string', 'max:100'], 'invigilators' => ['nullable', 'array'], 'invigilators.*' => ['integer'],
            'weights' => ['nullable', 'array'], 'weights.*' => ['integer', 'between:0,5'], 'seed' => ['nullable', 'integer', 'between:1,99999'],
            'committee' => ['nullable', 'array'], 'committee.*' => ['integer'], 'departments' => ['nullable', 'array'], 'levels' => ['nullable', 'array'],
        ]);
        $c = $timetable->config;
        $c['days'] = array_map('intval', $d['days']);
        $c['weights'] = array_map('intval', $d['weights'] ?? []) + ($c['weights'] ?? []);
        $c['seed'] = (int) ($d['seed'] ?? 1);
        $c['scope'] = ['departments' => array_map('intval', $d['departments'] ?? []), 'levels' => array_map('intval', $d['levels'] ?? [])];
        // Protected periods, one per line: "Fri 13:00-14:00 Jumat prayers", "2026-11-20 Convocation", "Wed 16:00-18:00 Sports"
        $c['blocked'] = [];
        foreach (preg_split('/\R/', (string) ($d['blocked'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $entry = [];
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $line, $m)) {
                $entry['date'] = $m[1];
            } elseif (preg_match('/^(mon|tue|wed|thu|fri|sat|sun)/i', $line, $m)) {
                $entry['day'] = array_search(strtolower($m[1]), ['', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true);
            } else {
                continue;
            }
            if ($r = self::ranges($line)) {
                [$entry['start'], $entry['end']] = $r[0];
            }
            $entry['label'] = trim(preg_replace('/^\S+\s*(\d{1,2}:\d{2}\s*[-–]\s*\d{1,2}:\d{2})?/', '', $line)) ?: 'Protected period';
            $c['blocked'][] = $entry;
        }
        if ($timetable->isExam()) {
            $c['start_date'] = $d['start_date'] ?? null;
            $c['end_date'] = $d['end_date'] ?? null;
            $c['sittings'] = self::ranges($d['sittings'] ?? '') ?: $c['sittings'];
            preg_match_all('/\d{4}-\d{2}-\d{2}/', (string) ($d['excluded_dates'] ?? ''), $m);
            $c['excluded_dates'] = array_values(array_unique($m[0]));
            foreach (['max_split', 'invigilator_ratio', 'min_invigilators', 'max_duties_per_day'] as $k) {
                isset($d[$k]) && $c[$k] = (int) $d[$k];
            }
            $c['invigilators'] = ['role' => $d['invigilator_role'] ?? null, 'users' => array_map('intval', $d['invigilators'] ?? [])];
        } else {
            $c['periods'] = self::ranges($d['periods'] ?? '') ?: $c['periods'];
            $c['max_consecutive'] = (int) ($d['max_consecutive'] ?? 3);
        }
        $timetable->update(['name' => $d['name'], 'config' => $c, 'committee' => array_map('intval', $d['committee'] ?? []) ?: null]);

        return redirect()->route('timetables.show', $timetable)->with('success', 'Rules saved. Generate again to apply them.');
    }

    // ---- items ----------------------------------------------------------------------------------------

    public function items(Timetable $timetable): View
    {
        return view('timetables.items', [
            'pagetitle' => 'What to schedule · ' . $timetable->title, 't' => $timetable,
            'items' => $timetable->items()->with('course.department', 'preferredVenue')->get()->sortBy(fn ($i) => [$i->course?->level, $i->course?->code, $i->activity, $i->group_label])->values(),
            'staff' => User::whereDoesntHave('student')->where('is_disabled', false)->orderBy('name')->pluck('name', 'id'),
            'venues' => Venue::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'types' => Lookups::options('venue_type'), 'facilities' => Lookups::options('venue_facility'),
            'courses' => Course::where('is_active', true)->orderBy('code')->get(['id', 'code', 'title']),
            'editable' => $timetable->isEditable() && $timetable->preparedBy(request()->user()),
        ]);
    }

    private function itemRules(Timetable $t): array
    {
        return [
            'students' => ['required', 'integer', 'min:0', 'max:20000'], 'meetings' => ['nullable', 'integer', 'between:1,10'],
            'duration' => ['required', 'integer', $t->isExam() ? 'between:15,480' : 'between:1,6'], 'group_label' => ['nullable', 'string', 'max:40'],
            'venue_type' => ['nullable', 'string', 'max:40'], 'facilities' => ['nullable', 'array'], 'staff' => ['nullable', 'array'], 'staff.*' => ['integer', 'exists:users,id'],
            'preferred_venue_id' => ['nullable', 'exists:venues,id'], 'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function updateItem(Request $request, Timetable $timetable, TimetableItem $item): RedirectResponse
    {
        abort_unless($item->timetable_id === $timetable->id, 404);
        if (! $timetable->isEditable() || ! $timetable->preparedBy($request->user())) {
            return back()->with('error', 'Only the committee can change a timetable being prepared.');
        }
        $d = $request->validate($this->itemRules($timetable));
        $d['staff'] = array_map('intval', $d['staff'] ?? []);
        $d['facilities'] = array_values($d['facilities'] ?? []);
        $d['is_active'] = $request->boolean('is_active');
        $d['meetings'] = $timetable->isExam() ? 1 : ($d['meetings'] ?? 1);
        $item->update($d);

        return back()->with('success', "{$item->label} updated.");
    }

    public function addItem(Request $request, Timetable $timetable): RedirectResponse
    {
        if (! $timetable->isEditable() || ! $timetable->preparedBy($request->user())) {
            return back()->with('error', 'Only the committee can change a timetable being prepared.');
        }
        $d = $request->validate(['course_id' => ['required', 'exists:courses,id'], 'activity' => ['required', Rule::in(array_keys(TimetableItem::ACTIVITIES))]] + $this->itemRules($timetable));
        $d['staff'] = array_map('intval', $d['staff'] ?? []);
        $d['facilities'] = array_values($d['facilities'] ?? []);
        $d['meetings'] = $timetable->isExam() ? 1 : ($d['meetings'] ?? 1);
        $d['activity'] = $timetable->isExam() ? 'exam' : $d['activity'];
        $item = $timetable->items()->create($d);

        return back()->with('success', "{$item->label} added. A group splits a large class: give each group a different name and its own size.");
    }

    public function syncItems(Request $request, Timetable $timetable): RedirectResponse
    {
        if (! $timetable->isEditable()) {
            return back()->with('error', 'Only a timetable being prepared can be changed.');
        }
        $n = $this->svc->syncItems($timetable, $request->boolean('refresh'));

        return back()->with('success', "{$n} new course(s) added." . ($request->boolean('refresh') ? ' Class sizes were recounted from registrations.' : ''));
    }

    // ---- generating and adjusting ---------------------------------------------------------------------

    public function generate(Request $request, Timetable $timetable): RedirectResponse
    {
        try {
            $s = $this->svc->generate($timetable, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $msg = "Placed {$s['placed']} of {$s['units']}" . ($s['unplaced'] ? "; {$s['unplaced']} could not be placed (see why below)" : '') . '. Hard rules broken: ' . $s['violations'] . '.';

        return redirect()->route('timetables.show', $timetable)->with($s['unplaced'] || $s['violations'] ? 'error' : 'success', $msg);
    }

    public function entry(Timetable $timetable, string $unit): View
    {
        $rows = $timetable->entries()->with('venue', 'item.course')->where('unit_key', $unit)->get();
        $check = $this->svc->check($timetable);
        $p = $check['problem'];
        abort_unless(isset($p->units[$unit]), 404);

        return view('timetables.entry', [
            'pagetitle' => $p->units[$unit]['code'] . ' · ' . $timetable->title, 't' => $timetable, 'unit' => $unit, 'u' => $p->units[$unit], 'rows' => $rows,
            'alternatives' => $timetable->isEditable() ? $this->svc->alternatives($timetable, $unit) : [],
            'problems' => collect($check['violations'])->filter(fn ($v) => in_array($unit, $v['units'], true))->pluck('message'),
            'slots' => $p->slots, 'venues' => collect($p->venues)->sortBy('code'),
            'editable' => $timetable->isEditable() && $timetable->preparedBy(request()->user()),
            'describe' => fn ($a) => $this->svc->describe($p, $a),
        ]);
    }

    public function move(Request $request, Timetable $timetable, string $unit): RedirectResponse
    {
        $d = $request->validate(['slots' => ['required', 'string'], 'venues' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:300']]);
        $slots = array_values(array_filter(explode(',', $d['slots'])));
        $venues = json_decode($d['venues'], true);
        if (! is_array($venues)) {
            // "venue id" from the manual form: seat the whole class there
            $item = $timetable->items()->find((int) preg_replace('/^i(\d+).*/', '$1', $unit));
            $venues = [(int) $d['venues'] => (int) ($item?->students ?? 0)];
        }
        // A lecture of several periods starts at the chosen period and runs on.
        $p = $this->svc->check($timetable)['problem'];
        $u = $p->units[$unit] ?? null;
        if ($u && count($slots) === 1 && $u['length'] > 1) {
            $group = $p->groups[$p->slots[$slots[0]]['group'] ?? ''] ?? [];
            $i = array_search($slots[0], $group, true);
            $slots = $i === false ? $slots : array_slice($group, $i, $u['length']);
        }
        try {
            $this->svc->move($timetable, $unit, $slots, $venues, $request->user(), $d['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('timetables.show', $timetable)->with('success', 'Moved and locked: regenerating keeps it where you put it.');
    }

    public function unlock(Request $request, Timetable $timetable, string $unit): RedirectResponse
    {
        try {
            $this->svc->unlock($timetable, $unit, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Unlocked: the next generation may move it.');
    }

    public function whatIf(Request $request, Timetable $timetable): View
    {
        $d = $request->validate(['close_venues' => ['nullable', 'array'], 'capacity' => ['nullable', 'array'], 'capacity.*' => ['nullable', 'integer', 'min:0'], 'extra_dates' => ['nullable', 'string'], 'extra_days' => ['nullable', 'array']]);
        $result = null;
        if ($request->has('run')) {
            preg_match_all('/\d{4}-\d{2}-\d{2}/', (string) ($d['extra_dates'] ?? ''), $m);
            $result = $this->svc->whatIf($timetable, [
                'close_venues' => array_map('intval', $d['close_venues'] ?? []), 'capacity' => array_filter($d['capacity'] ?? [], fn ($v) => $v !== null && $v !== ''),
                'extra_dates' => $m[0], 'extra_days' => array_map('intval', $d['extra_days'] ?? []),
            ]);
        }

        return view('timetables.whatif', ['pagetitle' => 'What if · ' . $timetable->title, 't' => $timetable, 'q' => $d, 'result' => $result, 'venues' => Venue::where('is_active', true)->orderBy('code')->get()]);
    }

    public function invigilators(Timetable $timetable): View
    {
        return view('timetables.invigilators', [
            'pagetitle' => 'Invigilation · ' . $timetable->title, 't' => $timetable,
            'duties' => $timetable->invigilators()->with('user', 'venue')->orderBy('date')->orderBy('starts_at')->get()->groupBy('slot'),
            'load' => $timetable->invigilators()->with('user')->get()->groupBy('user_id')->map(fn ($r) => [$r->first()->user, $r->count()])->sortByDesc(fn ($x) => $x[1]),
            'papers' => $timetable->entries()->with('course')->get()->groupBy(fn ($e) => $e->slots[0] . '|' . $e->venue_id),
        ]);
    }

    public function replan(Request $request, Timetable $timetable): RedirectResponse
    {
        if (! $timetable->isEditable()) {
            return back()->with('error', 'Return the timetable to the committee to change invigilators.');
        }
        $p = $this->svc->replanInvigilators($timetable);

        return back()->with($p['short'] ? 'error' : 'success', "{$p['assigned']} of {$p['needed']} duties assigned." . ($p['short'] ? ' Some halls are short of invigilators.' : ''));
    }

    // ---- workflow -------------------------------------------------------------------------------------

    private function step(callable $fn, string $ok): RedirectResponse
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }

    public function submit(Request $request, Timetable $timetable): RedirectResponse
    {
        return $this->step(fn () => $this->svc->submit($timetable, $request->user()), 'Sent for approval.');
    }

    public function approve(Request $request, Timetable $timetable): RedirectResponse
    {
        $d = $request->validate(['note' => ['nullable', 'string', 'max:500'], 'confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm you have reviewed it.']);
        if (($timetable->summary['unplaced'] ?? 0) > 0 && ! $request->boolean('accept_unplaced')) {
            return back()->with('error', 'Some items are not scheduled. Tick the box to approve it without them, or return it to the committee.');
        }

        return $this->step(fn () => $this->svc->approve($timetable, $request->user(), $d['note'] ?? null), 'Approved. It can now be published.');
    }

    public function returnIt(Request $request, Timetable $timetable): RedirectResponse
    {
        $d = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->step(fn () => $this->svc->returnToCommittee($timetable, $request->user(), $d['note']), 'Returned to the committee.');
    }

    public function publish(Request $request, Timetable $timetable): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm.']);
        $n = 0;

        return $this->step(function () use ($timetable, $request, &$n) {
            $n = $this->svc->publish($timetable, $request->user());
        }, 'Published. Students and staff have been notified.');
    }

    public function revise(Request $request, Timetable $timetable): RedirectResponse
    {
        try {
            $new = $this->svc->revise($timetable, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('timetables.show', $new)->with('success', "Version {$new->version} started from the published one. The published version stays in force until this one is published.");
    }

    public function destroy(Request $request, Timetable $timetable): RedirectResponse
    {
        if ($timetable->status !== 'draft' || $timetable->published_at || ! $timetable->preparedBy($request->user())) {
            return back()->with('error', 'Only an unpublished draft can be deleted, by its committee.');
        }
        $timetable->delete();

        return redirect()->route('timetables.index')->with('success', 'Draft deleted.');
    }
}
