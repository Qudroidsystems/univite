<?php

namespace App\Http\Controllers\Timetable;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\SemesterType;
use App\Models\Timetable;
use App\Models\TimetableItem;
use App\Models\TimetableTemplate;
use App\Models\User;
use App\Services\Timetable\TimetableWizard;
use App\Support\Lookups;
use App\Timetabling\Solver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** The timetable generation wizard: the whole school's lecture timetable set up and generated in one go. */
class TimetableWizardController extends Controller
{
    /** Settings a template keeps (everything except the session, semester, name and course-by-course changes). */
    private const TEMPLATE_KEYS = ['faculties', 'departments', 'programmes', 'levels', 'days', 'day_start', 'day_end', 'period_minutes', 'max_periods', 'breaks', 'half_days',
        'events', 'max_consecutive', 'max_per_day', 'avoid_breaks', 'strict_rooms', 'apply_priorities', 'compulsory_priority', 'unit_rules', 'weights', 'algorithm', 'candidates', 'vary_algorithms', 'seed'];

    public function __construct(private TimetableWizard $wizard)
    {
        $this->middleware('permission:Create timetables');
    }

    public function show(Request $request): View
    {
        $start = TimetableWizard::defaults() + ['algorithm' => 'quick', 'candidates' => 3, 'vary_algorithms' => true];
        if ($request->filled('template') && ($tpl = TimetableTemplate::find($request->query('template')))) {
            $start = $tpl->settings + $start;
        } elseif ($request->filled('from') && ($from = Timetable::find($request->query('from')))) {
            $start = ($from->config['wizard'] ?? []) + ['copy_from' => $from->id] + $start;
        }
        $current = AcademicSession::current();

        return view('timetables.wizard', [
            'pagetitle' => 'Timetable generation wizard', 's' => $start, 'current' => $current,
            'sessions' => AcademicSession::orderByDesc('id')->pluck('name', 'id'),
            'semesterTypes' => SemesterType::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'),
            'faculties' => Faculty::orderBy('name')->pluck('name', 'id'),
            'departments' => Department::with('faculty')->where('is_active', true)->orderBy('name')->get(),
            'programmes' => Programme::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'levels' => Course::whereNotNull('level')->distinct()->orderBy('level')->pluck('level'),
            'staff' => User::whereDoesntHave('student')->where('is_disabled', false)->orderBy('name')->pluck('name', 'id'),
            'templates' => TimetableTemplate::orderBy('name')->get(),
            'earlier' => Timetable::where('kind', 'lecture')->latest('id')->limit(30)->get(),
            'algorithms' => Solver::ALGORITHMS, 'preferences' => collect(Timetable::PREFERENCES)->filter(fn ($p) => $p[1] !== 'exam'),
            'activities' => collect(TimetableItem::ACTIVITIES)->except('exam'), 'venueTypes' => Lookups::options('venue_type'),
        ]);
    }

    private function input(Request $request, bool $full = true): array
    {
        $d = $request->validate([
            'name' => [$full ? 'required' : 'nullable', 'string', 'max:150'],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'], 'semester_type_id' => ['required', 'exists:semester_types,id'],
            'faculties' => ['nullable', 'array'], 'faculties.*' => ['integer', 'exists:faculties,id'],
            'departments' => ['nullable', 'array'], 'departments.*' => ['integer', 'exists:departments,id'],
            'programmes' => ['nullable', 'array'], 'programmes.*' => ['integer', 'exists:programmes,id'],
            'levels' => ['nullable', 'array'], 'levels.*' => ['integer'], 'committee' => ['nullable', 'array'], 'committee.*' => ['integer', 'exists:users,id'],
            'days' => ['required', 'array', 'min:1'], 'days.*' => ['integer', 'between:1,7'],
            'day_start' => ['required', 'date_format:H:i'], 'day_end' => ['required', 'date_format:H:i', 'after:day_start'],
            'period_minutes' => ['required', 'integer', 'between:20,240'], 'max_periods' => ['nullable', 'integer', 'between:1,20'],
            'breaks' => ['nullable', 'array', 'max:6'], 'breaks.*.after' => ['nullable', 'integer', 'between:1,20'], 'breaks.*.minutes' => ['nullable', 'integer', 'between:5,240'], 'breaks.*.label' => ['nullable', 'string', 'max:60'],
            'half_days' => ['nullable', 'array', 'max:7'], 'half_days.*.day' => ['nullable', 'integer', 'between:1,7'], 'half_days.*.ends_at' => ['nullable', 'date_format:H:i'], 'half_days.*.label' => ['nullable', 'string', 'max:60'],
            'events' => ['nullable', 'array', 'max:20'], 'events.*.day' => ['nullable', 'integer', 'between:1,7'], 'events.*.start' => ['nullable', 'date_format:H:i'], 'events.*.end' => ['nullable', 'date_format:H:i'], 'events.*.label' => ['nullable', 'string', 'max:60'],
            'max_consecutive' => ['required', 'integer', 'between:1,10'], 'max_per_day' => ['nullable', 'integer', 'between:0,16'],
            'avoid_breaks' => ['nullable', 'boolean'], 'strict_rooms' => ['nullable', 'boolean'], 'apply_priorities' => ['nullable', 'boolean'], 'compulsory_priority' => ['nullable', 'boolean'],
            'seed' => ['nullable', 'integer', 'between:1,99999'],
            'unit_rules' => ['nullable', 'array'], 'unit_rules.*.0' => ['nullable', 'integer', 'between:1,10'], 'unit_rules.*.1' => ['nullable', 'integer', 'between:1,4'],
            'weights' => ['nullable', 'array'], 'weights.*' => ['integer', 'between:0,5'],
            'copy_from' => ['nullable', 'exists:timetables,id'],
            'courses' => ['nullable', 'array'], 'courses.*.include' => ['nullable', 'boolean'], 'courses.*.meetings' => ['nullable', 'integer', 'between:1,10'],
            'courses.*.duration' => ['nullable', 'integer', 'between:1,4'], 'courses.*.priority' => ['nullable', 'integer', 'between:1,5'],
            'courses.*.activity' => ['nullable', Rule::in(['lecture', 'tutorial', 'practical'])], 'courses.*.venue_type' => ['nullable', 'string', 'max:40'],
            'algorithm' => ['nullable', Rule::in(array_keys(Solver::ALGORITHMS))], 'candidates' => ['nullable', 'integer', 'between:1,5'], 'vary_algorithms' => ['nullable', 'boolean'],
        ], ['day_end.after' => 'The day must end after it starts.']);
        foreach (['avoid_breaks', 'strict_rooms', 'apply_priorities', 'compulsory_priority', 'vary_algorithms'] as $k) {
            $d[$k] = $request->boolean($k);
        }
        foreach ($d['courses'] ?? [] as $id => $c) {
            $d['courses'][$id]['include'] = ! empty($c['include']);
        }
        $d['breaks'] = array_values(array_filter($d['breaks'] ?? [], fn ($b) => ! empty($b['after']) && ! empty($b['minutes'])));
        $d['half_days'] = array_values(array_filter($d['half_days'] ?? [], fn ($h) => ! empty($h['day']) && ! empty($h['ends_at'])));
        $d['events'] = array_values(array_filter($d['events'] ?? [], fn ($e) => ! empty($e['day']) && ! empty($e['start']) && ! empty($e['end'])));

        return $d;
    }

    /** Step 2: what would be scheduled, and what is missing. */
    public function courses(Request $request): JsonResponse
    {
        $d = $this->input($request, false);
        $d['courses'] = [];   // the defaults, before the committee's course-by-course changes

        return response()->json($this->wizard->courses($d, $request->user()));
    }

    /** Step 6: a full generation that saves nothing. */
    public function preview(Request $request): JsonResponse
    {
        @set_time_limit(600);
        try {
            return response()->json($this->wizard->preview($this->input($request, false), $request->user()));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Create the draft and generate the candidates. */
    public function store(Request $request): RedirectResponse
    {
        $d = $this->input($request);
        $n = (int) ($d['candidates'] ?? 1);
        $algorithms = $d['vary_algorithms'] ? array_keys(Solver::ALGORITHMS) : [$d['algorithm'] ?? 'quick'];
        $candidates = [];
        for ($i = 0; $i < $n; $i++) {
            $candidates[] = ['algorithm' => $algorithms[$i % count($algorithms)], 'seed' => (int) ($d['seed'] ?? 1) + $i * 7919];
        }
        try {
            $t = $this->wizard->create($d, $request->user(), $candidates);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('timetables.runs', $t)->with('success', "Draft timetable created with {$t->items()->where('is_active', true)->count()} course(s). "
            . "{$n} " . \Illuminate\Support\Str::plural('candidate', $n) . ' are being generated: compare them below and use the best one.');
    }

    public function saveTemplate(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate(['template_name' => ['required', 'string', 'max:120'], 'template_description' => ['nullable', 'string', 'max:500']]);
        $d = $this->input($request, false);
        $tpl = TimetableTemplate::updateOrCreate(['name' => $request->input('template_name')],
            ['description' => $request->input('template_description'), 'settings' => array_intersect_key($d, array_flip(self::TEMPLATE_KEYS)), 'created_by' => $request->user()->id]);

        return $request->expectsJson() ? response()->json(['id' => $tpl->id, 'name' => $tpl->name, 'message' => 'Template saved.'])
            : back()->with('success', "Template \"{$tpl->name}\" saved.");
    }

    public function deleteTemplate(TimetableTemplate $template): RedirectResponse
    {
        $template->delete();

        return redirect()->route('timetables.wizard')->with('success', 'Template deleted.');
    }
}
