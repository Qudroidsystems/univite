<?php

namespace App\Http\Controllers\Accommodation;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AccommodationApplication;
use App\Models\BedAllocation;
use App\Services\Accommodation\AccommodationService;
use App\Services\Accommodation\AccommodationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** A student's hostel application and place. */
class MyAccommodationController extends Controller
{
    public function __construct(private AccommodationService $accommodation, private AccommodationSettings $settings)
    {
    }

    public function show(Request $request): View
    {
        $student = $request->user()->student;
        abort_unless($student !== null, 403, 'This page is for students.');
        $session = AcademicSession::current();
        $hostels = $this->accommodation->hostelsFor($student);

        return view('accommodation.mine', [
            'pagetitle' => 'My Accommodation', 'session' => $session, 'settings' => $this->settings, 'hostels' => $hostels,
            'free' => $session ? $hostels->mapWithKeys(fn ($h) => [$h->id => $this->accommodation->freeBed($h, $session) !== null]) : collect(),
            'allocation' => $session ? $this->accommodation->current($student, $session) : null,
            'application' => $session ? AccommodationApplication::where('student_id', $student->id)->where('academic_session_id', $session->id)->first() : null,
            'history' => BedAllocation::with('room.hostel', 'session')->where('student_id', $student->id)->whereNotIn('status', BedAllocation::HOLDING)->latest('id')->limit(10)->get(),
        ]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $student = $request->user()->student;
        abort_unless($student !== null, 403);
        $d = $request->validate(['preferences' => ['required', 'array', 'min:1'], 'preferences.*' => ['nullable', 'integer']]);
        $session = AcademicSession::current();
        abort_unless($session !== null, 404);
        try {
            $this->accommodation->apply($student, $session, $d['preferences']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Application received. You will be told when beds are given out.');
    }

    public function withdraw(Request $request): RedirectResponse
    {
        $student = $request->user()->student;
        $session = AcademicSession::current();
        $app = $student && $session ? AccommodationApplication::where('student_id', $student->id)->where('academic_session_id', $session->id)->first() : null;
        abort_unless($app !== null, 404);
        try {
            $this->accommodation->withdraw($app);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Application withdrawn.');
    }
}
