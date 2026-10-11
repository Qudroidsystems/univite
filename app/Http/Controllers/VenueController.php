<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\Venue;
use App\Models\VenueBooking;
use App\Models\VenueClosure;
use App\Models\VenueIssue;
use App\Services\Messenger;
use App\Services\OperationAudit;
use App\Services\TimetableService;
use App\Support\Lookups;
use App\Timetabling\ProblemBuilder;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The venue register: halls, classrooms, laboratories; closures, faults, bookings, availability and usage. */
class VenueController extends Controller
{
    public function __construct(private OperationAudit $audit, private ProblemBuilder $builder)
    {
        $this->middleware('permission:View venues')->only('index', 'show', 'availability', 'usage');
        $this->middleware('permission:Create venues')->only('create', 'store');
        $this->middleware('permission:Edit venues')->only('edit', 'update');
        $this->middleware('permission:Delete venues')->only('destroy');
        $this->middleware('permission:Set venue closures')->only('close', 'lift');
        $this->middleware('permission:Record venue faults')->only('report');
        $this->middleware('permission:Clear venue faults')->only('resolve');
        $this->middleware('permission:Create venue bookings')->only('book');
    }

    public function index(Request $request): View
    {
        $q = Venue::with('department')->withCount(['issues as open_issues_count' => fn ($q) => $q->where('status', '!=', 'resolved')])
            ->when($request->filled('type'), fn ($w) => $w->where('type', $request->type))
            ->when($request->filled('status'), fn ($w) => $w->where('status', $request->status))
            ->when($request->filled('building'), fn ($w) => $w->where('building', $request->building))
            ->when(trim((string) $request->q) !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$request->q}%")->orWhere('code', 'like', "%{$request->q}%")))
            ->orderByDesc('is_active')->orderBy('code');

        return view('venues.index', [
            'pagetitle' => 'Venues', 'venues' => $q->paginate(30)->withQueryString(),
            'types' => Lookups::options('venue_type'), 'buildings' => Venue::whereNotNull('building')->distinct()->orderBy('building')->pluck('building'),
            'stats' => [
                'venues' => Venue::where('is_active', true)->count(),
                'teaching' => Venue::where('is_active', true)->sum('teaching_capacity'),
                'exam' => Venue::where('is_active', true)->sum('exam_capacity'),
                'out' => Venue::where('is_active', true)->whereIn('status', ['maintenance', 'closed'])->count(),
                'faults' => VenueIssue::where('status', '!=', 'resolved')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return $this->form(new Venue(['uses' => array_keys(Venue::USES), 'shared_exams' => true, 'is_active' => true]));
    }

    public function edit(Venue $venue): View
    {
        return $this->form($venue);
    }

    private function form(Venue $venue): View
    {
        return view('venues.form', [
            'pagetitle' => $venue->exists ? 'Edit ' . $venue->name : 'New venue', 'venue' => $venue,
            'types' => Lookups::options('venue_type'), 'facilities' => Lookups::options('venue_facility'),
            'departments' => Department::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    private function rules(?Venue $venue): array
    {
        $seat = 'regex:/^R\d{2}-S\d{2}$/';

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('venues', 'code')->ignore($venue?->id)],
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(Lookups::options('venue_type')) ?: ['lecture_hall'])],
            'campus' => ['nullable', 'string', 'max:100'], 'building' => ['nullable', 'string', 'max:100'],
            'floor' => ['nullable', 'string', 'max:40'], 'room_number' => ['nullable', 'string', 'max:40'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'capacity' => ['required', 'integer', 'min:0', 'max:20000'],
            'teaching_capacity' => ['required', 'integer', 'min:0', 'lte:capacity'],
            'exam_capacity' => ['required', 'integer', 'min:0', 'lte:capacity'],
            'seat_rows' => ['nullable', 'integer', 'min:1', 'max:200', 'required_with:seats_per_row'],
            'seats_per_row' => ['nullable', 'integer', 'min:1', 'max:200', 'required_with:seat_rows'],
            'blocked_seats' => ['nullable', 'string', 'max:5000'], 'accessible_seats' => ['nullable', 'string', 'max:2000'],
            'uses' => ['required', 'array', 'min:1'], 'uses.*' => [Rule::in(array_keys(Venue::USES))],
            'facilities' => ['nullable', 'array'], 'facilities.*' => ['string', 'max:100'],
            'shared_exams' => ['nullable', 'boolean'], 'wheelchair_access' => ['nullable', 'boolean'],
            'entry_info' => ['nullable', 'string', 'max:150'],
            'status' => ['required', Rule::in(array_keys(Venue::STATUSES))],
            'notes' => ['nullable', 'string', 'max:2000'], 'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function prepare(Request $request, array $d): array
    {
        $seats = fn ($s) => collect(preg_split('/[\s,;]+/', strtoupper((string) $s)))->filter()->map(function ($x) {
            return preg_match('/^R?(\d+)-?S?(\d+)$/', $x, $m) ? Venue::seatLabel((int) $m[1], (int) $m[2]) : null;
        })->filter()->unique()->values()->all();
        $d['blocked_seats'] = $seats($d['blocked_seats'] ?? '') ?: null;
        $d['accessible_seats'] = $seats($d['accessible_seats'] ?? '') ?: null;
        foreach (['shared_exams', 'wheelchair_access', 'is_active'] as $b) {
            $d[$b] = $request->boolean($b);
        }
        $d['facilities'] = array_values($d['facilities'] ?? []);

        return $d;
    }

    /** A drawn seat map must hold the examination capacity once blocked seats are taken out. */
    private function checkMap(array $d): ?string
    {
        if (empty($d['seat_rows'])) {
            return null;
        }
        $usable = $d['seat_rows'] * $d['seats_per_row'] - count($d['blocked_seats'] ?? []);

        return $usable < $d['exam_capacity'] ? "The seat map has {$usable} usable seats but the examination capacity is {$d['exam_capacity']}. Add rows or lower the examination capacity." : null;
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->prepare($request, $request->validate($this->rules(null)));
        if ($err = $this->checkMap($d)) {
            return back()->withInput()->withErrors(['seat_rows' => $err]);
        }
        $venue = Venue::create($d);

        return redirect()->route('venues.show', $venue)->with('success', "{$venue->name} added to the venue register.");
    }

    public function update(Request $request, Venue $venue): RedirectResponse
    {
        $d = $this->prepare($request, $request->validate($this->rules($venue)));
        if ($err = $this->checkMap($d)) {
            return back()->withInput()->withErrors(['seat_rows' => $err]);
        }
        $before = $venue->only(['capacity', 'teaching_capacity', 'exam_capacity', 'status', 'is_active']);
        $venue->update($d);
        if ($before !== $venue->only(array_keys($before))) {
            $this->audit->note('venue_change', 'venues', $request->user(), 'venue changed', $venue, $before, $venue->only(array_keys($before)));
        }

        return redirect()->route('venues.show', $venue)->with('success', 'Venue updated.' . (in_array($venue->status, ['maintenance', 'closed'], true) ? ' It is now out of use: check the affected timetables below.' : ''));
    }

    public function destroy(Venue $venue): RedirectResponse
    {
        if (TimetableEntry::where('venue_id', $venue->id)->exists() || $venue->bookings()->exists() || DB::table('exam_seats')->where('venue_id', $venue->id)->exists()) {
            return back()->with('error', 'Timetables, seating plans or bookings use this venue, so it cannot be deleted. Mark it inactive instead.');
        }
        $venue->delete();

        return redirect()->route('venues.index')->with('success', 'Venue deleted.');
    }

    public function show(Venue $venue, TimetableService $timetables): View
    {
        $venue->load('department', 'closures.creator', 'issues.reporter', 'issues.resolver');
        $closure = $venue->closures->first(fn ($c) => ! $c->lifted_at && $c->ends_on->gte(today()));
        $impact = collect();
        if ($closure || in_array($venue->status, ['maintenance', 'closed'], true) || $venue->issues->contains(fn ($i) => $i->severity === 'blocking' && $i->status !== 'resolved')) {
            $impact = $closure && $venue->status === 'available' ? $timetables->venueImpact($venue, $closure->starts_on, $closure->ends_on) : $timetables->venueImpact($venue);
        }

        return view('venues.show', [
            'pagetitle' => $venue->name, 'venue' => $venue, 'blocked' => $venue->blockedReason(today()), 'impact' => $impact,
            'bookings' => $venue->bookings()->with('booker')->where('status', 'confirmed')->whereDate('date', '>=', today())->orderBy('date')->orderBy('starts_at')->limit(30)->get(),
            'usage' => TimetableEntry::with('timetable', 'course')->where('venue_id', $venue->id)->whereHas('timetable', fn ($q) => $q->whereIn('status', ['published', 'approved', 'review', 'draft']))
                ->orderBy('date')->orderBy('day')->orderBy('starts_at')->limit(200)->get()->groupBy('timetable_id'),
            'map' => $venue->seatMap(),
        ]);
    }

    // ---- closures, faults, bookings -------------------------------------------------------------------

    public function close(Request $request, Venue $venue, Messenger $messenger, TimetableService $timetables): RedirectResponse
    {
        $d = $request->validate(['starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $c = VenueClosure::create($d + ['venue_id' => $venue->id, 'created_by' => $request->user()->id]);
        $this->audit->note('venue_closure', 'venues', $request->user(), 'closed', $venue, null, ['from' => $d['starts_on'], 'to' => $d['ends_on']], $d['reason']);
        $impact = $timetables->venueImpact($venue, Carbon::parse($d['starts_on']), Carbon::parse($d['ends_on']));
        if ($impact->isNotEmpty()) {
            $people = \App\Models\User::permission('Create timetables')->get();
            foreach ($people as $u) {
                $messenger->notify($u, 'venues', 'Venue closure affects timetables', "{$venue->name} is closed {$c->starts_on->format('j M')}–{$c->ends_on->format('j M')} ({$d['reason']}). {$impact->count()} class(es) or paper(s) are affected.", $venue, route('venues.show', $venue), 'warning');
            }
        }

        return back()->with('success', "Closure recorded. {$impact->count()} timetable entr" . ($impact->count() === 1 ? 'y is' : 'ies are') . ' affected; replacements are suggested below.');
    }

    public function lift(Request $request, Venue $venue, VenueClosure $closure): RedirectResponse
    {
        abort_unless($closure->venue_id === $venue->id, 404);
        $closure->update(['lifted_at' => now(), 'lifted_by' => $request->user()->id]);
        $this->audit->note('venue_reopen', 'venues', $request->user(), 'closure lifted', $venue, ['closed' => $closure->starts_on->toDateString() . ' to ' . $closure->ends_on->toDateString()], null);

        return back()->with('success', 'Closure lifted. The venue can be used on those dates again.');
    }

    public function report(Request $request, Venue $venue): RedirectResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:150'], 'details' => ['nullable', 'string', 'max:2000'], 'severity' => ['required', Rule::in(array_keys(VenueIssue::SEVERITIES))], 'inspected_on' => ['nullable', 'date']]);
        VenueIssue::create($d + ['venue_id' => $venue->id, 'reported_by' => $request->user()->id]);

        return back()->with('success', $d['severity'] === 'blocking' ? 'Fault reported. The venue is blocked from new allocations until the fault is resolved.' : 'Fault reported.');
    }

    public function resolve(Request $request, Venue $venue, VenueIssue $issue): RedirectResponse
    {
        abort_unless($issue->venue_id === $venue->id, 404);
        $d = $request->validate(['status' => ['required', Rule::in(['in_progress', 'resolved'])], 'resolution' => ['required_if:status,resolved', 'nullable', 'string', 'max:500']]);
        $issue->update($d + ($d['status'] === 'resolved' ? ['resolved_by' => $request->user()->id, 'resolved_at' => now()] : []));
        if ($d['status'] === 'resolved') {
            $this->audit->note('venue_fault', 'venues', $request->user(), 'fault resolved', $venue, ['fault' => $issue->title, 'severity' => $issue->severity], ['status' => 'resolved'], $d['resolution'] ?? null);
        }

        return back()->with('success', $d['status'] === 'resolved' ? 'Fault resolved.' : 'Marked as being fixed.');
    }

    public function book(Request $request, Venue $venue): RedirectResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:150'], 'date' => ['required', 'date', 'after_or_equal:today'], 'starts_at' => ['required', 'date_format:H:i'], 'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'], 'attendees' => ['nullable', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:500']]);
        $date = Carbon::parse($d['date']);
        if ($why = $this->busy($venue, $date, $d['starts_at'], $d['ends_at'])) {
            return back()->withInput()->with('error', "{$venue->name} is not free then: {$why}.");
        }
        if (! empty($d['attendees']) && $d['attendees'] > max($venue->capacity, 1)) {
            return back()->withInput()->with('error', "{$venue->name} holds {$venue->capacity}.");
        }
        VenueBooking::create($d + ['venue_id' => $venue->id, 'booked_by' => $request->user()->id]);

        return back()->with('success', 'Venue booked.');
    }

    public function cancelBooking(Request $request, Venue $venue, VenueBooking $booking): RedirectResponse
    {
        abort_unless($booking->venue_id === $venue->id, 404);
        abort_unless($booking->booked_by === $request->user()->id || $request->user()->can('Cancel venue bookings'), 403);
        $booking->update(['status' => 'cancelled']);

        return back()->with('success', 'Booking cancelled.');
    }

    // ---- availability and usage -----------------------------------------------------------------------

    /** Why a venue is not free on a date between two times, or null. Looks at closures, faults, bookings and published timetables. */
    private function busy(Venue $venue, Carbon $date, string $from, string $to): ?string
    {
        if ($why = $venue->blockedReason($date)) {
            return $why;
        }
        $b = $venue->bookings()->where('status', 'confirmed')->whereDate('date', $date)->get()->first(fn ($b) => ProblemBuilder::overlaps($from, $to, substr($b->starts_at, 0, 5), substr($b->ends_at, 0, 5)));
        if ($b) {
            return 'booked for ' . $b->title;
        }
        $e = TimetableEntry::with('course', 'timetable.session')->where('venue_id', $venue->id)->whereHas('timetable', fn ($q) => $q->where('status', 'published'))
            ->where(fn ($q) => $q->whereDate('date', $date)->orWhere(fn ($w) => $w->whereNull('date')->where('day', $date->dayOfWeekIso)))->get()
            ->first(fn ($e) => ProblemBuilder::overlaps($from, $to, substr($e->starts_at, 0, 5), substr($e->ends_at, 0, 5)) && $this->inTeachingTime($e, $date));

        return $e ? ($e->timetable->isExam() ? 'examination: ' : 'lecture: ') . $e->course?->code : null;
    }

    /** A weekly lecture only occupies a room while its semester's teaching runs (if the dates are known). */
    private function inTeachingTime(TimetableEntry $e, Carbon $date): bool
    {
        if ($e->date) {
            return true;
        }
        $sem = \App\Models\Semester::where('academic_session_id', $e->timetable->academic_session_id)->where('sort_order', $e->timetable->semesterType?->sort_order)->first();

        return ! $sem?->start_date || ! $sem?->end_date || $date->between($sem->start_date, $sem->end_date);
    }

    public function availability(Request $request): View
    {
        $d = $request->validate(['date' => ['nullable', 'date'], 'from' => ['nullable', 'date_format:H:i'], 'to' => ['nullable', 'date_format:H:i'], 'seats' => ['nullable', 'integer', 'min:1'], 'use' => ['nullable', Rule::in(array_keys(Venue::USES))], 'type' => ['nullable', 'string'], 'facilities' => ['nullable', 'array']]);
        $free = collect();
        $taken = collect();
        if (! empty($d['date']) && ! empty($d['from']) && ! empty($d['to'])) {
            $date = Carbon::parse($d['date']);
            $seats = (int) ($d['seats'] ?? 1);
            $use = $d['use'] ?? 'lecture';
            foreach (Venue::with('closures', 'issues')->where('is_active', true)->get() as $v) {
                $cap = $use === 'exam' ? $v->exam_capacity : ($use === 'meeting' ? $v->capacity : ($v->teaching_capacity ?: $v->capacity));
                if (! $v->allows($use) || ($d['type'] ?? null) && $v->type !== $d['type'] || ! $v->hasFacilities($d['facilities'] ?? []) || $cap < $seats) {
                    continue;
                }
                ($why = $this->busy($v, $date, $d['from'], $d['to'])) ? $taken->push([$v, $why, $cap]) : $free->push([$v, $cap]);
            }
            $free = $free->sortBy(fn ($x) => $x[1])->values(); // smallest suitable first
        }

        return view('venues.availability', [
            'pagetitle' => 'Find a free venue', 'q' => $d, 'free' => $free, 'taken' => $taken,
            'types' => Lookups::options('venue_type'), 'facilities' => Lookups::options('venue_facility'),
        ]);
    }

    /** How much each venue is used by the published timetables and bookings, with under- and over-used ones flagged. */
    public function usage(Request $request): View
    {
        $lecture = Timetable::where('kind', 'lecture')->where('status', 'published')->latest('published_at')->get()->unique(fn ($t) => $t->academic_session_id . '-' . $t->semester_type_id)->first();
        $lecture = $request->filled('lecture') ? Timetable::find($request->lecture) : $lecture;
        $exam = $request->filled('exam') ? Timetable::find($request->exam) : Timetable::where('kind', 'exam')->where('status', 'published')->latest('published_at')->first();

        $periods = $lecture ? count($lecture->config['days'] ?? []) * count($lecture->config['periods'] ?? []) : 0;
        $sittings = 0;
        if ($exam) {
            $sittings = count($this->builder->build($exam)->slots);
        }
        $rows = Venue::where('is_active', true)->orderBy('code')->get()->map(function (Venue $v) use ($lecture, $exam, $periods, $sittings) {
            $lp = $lecture ? TimetableEntry::where('timetable_id', $lecture->id)->where('venue_id', $v->id)->get()->sum(fn ($e) => count($e->slots)) : 0;
            $seatsUsed = $lecture ? TimetableEntry::where('timetable_id', $lecture->id)->where('venue_id', $v->id)->avg('seats') : null;
            $es = $exam ? TimetableEntry::where('timetable_id', $exam->id)->where('venue_id', $v->id)->get()->unique(fn ($e) => $e->slots[0])->count() : 0;
            $lectureShare = $periods && $v->allows('lecture') ? round($lp / $periods * 100) : null;
            $examShare = $sittings && $v->allows('exam') && $v->exam_capacity ? round($es / $sittings * 100) : null;
            $fill = $seatsUsed && ($v->teaching_capacity ?: $v->capacity) ? round($seatsUsed / ($v->teaching_capacity ?: $v->capacity) * 100) : null;

            return ['venue' => $v, 'lecture_periods' => $lp, 'lecture_share' => $lectureShare, 'fill' => $fill, 'exam_sittings' => $es, 'exam_share' => $examShare,
                'bookings' => $v->bookings()->where('status', 'confirmed')->whereDate('date', '>=', today()->subDays(90))->count()];
        });

        return view('venues.usage', [
            'pagetitle' => 'Venue usage', 'rows' => $rows, 'lecture' => $lecture, 'exam' => $exam, 'periods' => $periods, 'sittings' => $sittings,
            'lectures' => Timetable::with('session')->where('kind', 'lecture')->whereIn('status', ['published', 'superseded'])->latest('id')->get(),
            'exams' => Timetable::with('session')->where('kind', 'exam')->whereIn('status', ['published', 'superseded'])->latest('id')->get(),
        ]);
    }
}
