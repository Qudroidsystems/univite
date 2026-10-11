<?php

namespace App\Http\Controllers;

use App\Models\ExamSeat;
use App\Models\SeatingPlan;
use App\Models\Timetable;
use App\Models\Venue;
use App\Services\SeatingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** Examination seating plans: generate, check, adjust, approve and publish; hall maps and invigilators' lists. */
class SeatingController extends Controller
{
    public function __construct(private SeatingService $svc)
    {
        $this->middleware('permission:View exam seating|Generate exam seating|Record exam attendance')->only('show', 'hall');
        $this->middleware('permission:Generate exam seating')->only('generate');
        $this->middleware('permission:Approve exam seating')->only('approve');
        $this->middleware('permission:Publish exam seating')->only('publish');
        $this->middleware('permission:Assign exam seats')->only('move', 'seatLate', 'release');
        $this->middleware('permission:Record exam attendance')->only('attendance');
    }

    private function plan(Timetable $t): ?SeatingPlan
    {
        return $t->seatingPlans()->where('status', '!=', 'superseded')->latest('id')->first() ?? $t->seatingPlans()->latest('id')->first();
    }

    public function show(Timetable $timetable): View
    {
        abort_unless($timetable->isExam(), 404);
        $plan = $this->plan($timetable);

        return view('seating.show', [
            'pagetitle' => 'Seating · ' . $timetable->title, 't' => $timetable, 'plan' => $plan,
            'halls' => $plan ? $plan->seats()->with('venue', 'course')->get()->groupBy(fn ($s) => $s->slot . '|' . $s->venue_id)->sortKeys() : collect(),
            'drift' => $plan && $plan->status !== 'superseded' ? $this->svc->drift($plan) : ['missing' => [], 'gone' => []],
            'adjacent' => $plan ? $this->svc->validate($plan) : 0,
            'published' => $timetable->seatingPlans()->where('status', 'published')->first(),
        ]);
    }

    public function generate(Request $request, Timetable $timetable): RedirectResponse
    {
        $d = $request->validate(['pattern' => ['required', Rule::in(array_keys(SeatingPlan::PATTERNS))], 'order' => ['required', 'in:matric,random'], 'reserve_accessible' => ['nullable', 'boolean']]);
        $d['reserve_accessible'] = $request->boolean('reserve_accessible');
        try {
            $plan = $this->svc->generate($timetable, $d, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $s = $plan->summary;

        return back()->with($s['adjacent'] || $s['unseated'] || $s['clash_count'] ? 'error' : 'success', "Seated {$s['seated']} candidate(s) in {$s['halls']} hall sitting(s). Same-paper neighbours: {$s['adjacent']}. Not seated: {$s['unseated']}. Clashes: {$s['clash_count']}.");
    }

    public function hall(Request $request, Timetable $timetable, string $slot, Venue $venue): View
    {
        $plan = $this->plan($timetable);
        abort_unless($plan, 404);
        $seats = $plan->seats()->with('student', 'course')->where('slot', $slot)->where('venue_id', $venue->id)->orderBy('seat_no')->get();
        $q = trim((string) $request->q);

        return view('seating.hall', [
            'pagetitle' => $venue->name . ' · seating', 't' => $timetable, 'plan' => $plan, 'venue' => $venue, 'slot' => $slot, 'seats' => $seats,
            'list' => $q === '' ? $seats : $seats->filter(fn ($s) => str_contains(strtolower($s->student?->matric_number . ' ' . $s->student?->full_name), strtolower($q))),
            'map' => $venue->seatMap(), 'courses' => $seats->pluck('course.code', 'course_id')->unique()->values(),
            'halls' => $timetable->entries()->with('venue')->whereNotNull('venue_id')->get()->filter(fn ($e) => $e->slots[0] === $slot)->pluck('venue')->unique('id'),
        ]);
    }

    public function move(Request $request, ExamSeat $seat): RedirectResponse
    {
        $d = $request->validate(['venue_id' => ['required', 'exists:venues,id'], 'seat_label' => ['required', 'string', 'max:20'], 'reason' => ['required', 'string', 'min:5', 'max:300'], 'accept_adjacent' => ['nullable', 'boolean']]);
        try {
            $s = $this->svc->move($seat, (int) $d['venue_id'], $d['seat_label'], $d['reason'], $request->user(), $request->boolean('accept_adjacent'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$s->student?->full_name} moved to {$s->seat_number}." . ($s->plan->status === 'published' ? ' The student has been told.' : ''));
    }

    public function seatLate(Request $request, SeatingPlan $plan): RedirectResponse
    {
        try {
            $n = $this->svc->seatLate($plan, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$n} late registrant(s) seated.");
    }

    public function release(Request $request, SeatingPlan $plan): RedirectResponse
    {
        try {
            $n = $this->svc->releaseGone($plan, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$n} seat(s) released.");
    }

    public function approve(Request $request, SeatingPlan $plan): RedirectResponse
    {
        try {
            $this->svc->approve($plan, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Seating approved.');
    }

    public function publish(Request $request, SeatingPlan $plan): RedirectResponse
    {
        try {
            $n = $this->svc->publish($plan, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Published. {$n} student(s) can now see their seat numbers and have been notified.");
    }

    public function attendance(Request $request, ExamSeat $seat): RedirectResponse
    {
        $d = $request->validate(['mark' => ['nullable', 'in:present,absent']]);
        $this->svc->attendance($seat, $d['mark'] ?? null, $request->user());

        return back();
    }
}
