<?php

namespace App\Http\Controllers\Health;

use App\Data\Health\HealthDetails;
use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use App\Models\HealthAccessLog;
use App\Services\Health\HealthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** A person's own health record, visits, excuse notes, and who has looked at their record. */
class MyHealthController extends Controller
{
    public function __construct(private HealthService $health)
    {
    }

    public function show(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->student || $user->staffProfile, 403, 'This page is for students and staff.');

        return view('health.mine', ['pagetitle' => 'My Health', 'record' => $this->health->record($user),
            'visits' => ClinicVisit::where('user_id', $user->id)->latest('seen_at')->get(),
            'log' => HealthAccessLog::with('viewer')->where('user_id', $user->id)->latest('id')->limit(20)->get()]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->student || $request->user()->staffProfile, 403);
        $this->health->updateOwn($request->user(), HealthDetails::fromArray($request->validate(HealthDetails::rules())));

        return back()->with('success', 'Saved. The health centre can now see these details.');
    }

    public function excuse(Request $request, ClinicVisit $visit): View
    {
        abort_unless($visit->user_id === $request->user()->id && $visit->hasExcuse(), 404);

        return view('health.excuse', ['pagetitle' => 'Excuse note', 'visit' => $visit->load('user.student.programme', 'user.staffProfile', 'clinician')]);
    }
}
