<?php

namespace App\Http\Controllers\Health;

use App\Data\Health\Examination;
use App\Data\Health\HealthDetails;
use App\Data\Health\VisitInput;
use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use App\Models\HealthAccessLog;
use App\Models\User;
use App\Services\Campus\PersonFinder;
use App\Services\Health\HealthService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The health centre: find a patient, see and update their record, examinations, visits, excuse notes and statistics. */
class ClinicController extends Controller
{
    public function __construct(private HealthService $health, private PersonFinder $people)
    {
        $this->middleware('permission:View health records|Record clinic visits|Record medical examinations')->only('index', 'patient', 'excuse');
        $this->middleware('permission:Record clinic visits|Record medical examinations')->only('saveDetails');
        $this->middleware('permission:Record clinic visits')->only('storeVisit');
        $this->middleware('permission:Record medical examinations')->only('examine');
        $this->middleware('permission:View clinic statistics')->only('stats');
    }

    public function index(Request $request): View|RedirectResponse
    {
        if ($typed = trim((string) $request->query('patient'))) {
            $user = $this->people->find($typed);
            if ($user) {
                return redirect()->route('clinic.patient', $user);
            }
            session()->flash('error', 'Nobody found with that matric number, staff number or email.');
        }

        return view('health.index', ['pagetitle' => 'Health Centre',
            'today' => ClinicVisit::with('user', 'clinician')->whereDate('seen_at', today())->latest('seen_at')->get(),
            'counts' => ['today' => ClinicVisit::whereDate('seen_at', today())->count(), 'week' => ClinicVisit::where('seen_at', '>=', now()->startOfWeek())->count(),
                'resting' => ClinicVisit::whereDate('excused_from', '<=', today())->whereDate('excused_to', '>=', today())->count()]]);
    }

    public function patient(Request $request, User $user): View
    {
        abort_unless($user->student || $user->staffProfile, 404);
        $this->health->logAccess($user, $request->user(), 'viewed', null, $request->ip());

        return view('health.patient', ['pagetitle' => 'Health record', 'patient' => $user, 'label' => $this->people->label($user), 'record' => $this->health->record($user),
            'visits' => ClinicVisit::with('clinician')->where('user_id', $user->id)->latest('seen_at')->get(),
            'log' => HealthAccessLog::with('viewer')->where('user_id', $user->id)->latest('id')->limit(15)->get()]);
    }

    public function saveDetails(Request $request, User $user): RedirectResponse
    {
        $this->health->updateByClinic($user, HealthDetails::fromArray($request->validate(HealthDetails::rules())), $request->user(), $request->ip());

        return back()->with('success', 'Health details saved.');
    }

    public function examine(Request $request, User $user): RedirectResponse
    {
        $this->health->examine($user, Examination::fromArray($request->validate(Examination::rules())), $request->user(), $request->ip());

        return back()->with('success', 'Examination recorded.' . ($user->student ? ' Clearance has been updated.' : ''));
    }

    public function storeVisit(Request $request, User $user): RedirectResponse
    {
        $visit = $this->health->recordVisit($user, VisitInput::fromArray($request->validate(VisitInput::rules())), $request->user(), $request->ip());

        return back()->with('success', 'Visit recorded.' . ($visit->hasExcuse() ? ' An excuse note is ready to print.' : ''));
    }

    /** A printable excuse note: dates only, no diagnosis. */
    public function excuse(ClinicVisit $visit): View
    {
        abort_unless($visit->hasExcuse(), 404);

        return view('health.excuse', ['pagetitle' => 'Excuse note', 'visit' => $visit->load('user.student.programme', 'user.staffProfile', 'clinician')]);
    }

    public function stats(Request $request): View
    {
        $d = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = CarbonImmutable::parse($d['from'] ?? now()->startOfMonth());
        $to = CarbonImmutable::parse($d['to'] ?? now());

        return view('health.stats', ['pagetitle' => 'Clinic statistics', 'stats' => $this->health->stats($from, $to), 'from' => $from, 'to' => $to]);
    }
}
