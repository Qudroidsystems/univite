<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\SessionStateChange;
use App\Services\SessionLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Move a session through its stages. Each move is checked first, needs a reason where it matters, and is logged. */
class SessionLifecycleController extends Controller
{
    public function __construct(private SessionLifecycle $lifecycle)
    {
        $this->middleware('permission:View academic sessions')->only('show');
        $this->middleware('permission:Change session status')->only('change');
    }

    public function show(AcademicSession $session): View
    {
        $options = [];
        foreach ($this->lifecycle->next($session) as $to) {
            $options[$to] = $this->lifecycle->check($session, $to);
        }

        return view('sessions.lifecycle', [
            'pagetitle' => $session->name . ' · status',
            'session'   => $session->loadCount('semesters'),
            'options'   => $options,
            'history'   => SessionStateChange::where('academic_session_id', $session->id)->with('user')->latest('id')->get(),
            'lifecycle' => $this->lifecycle,
        ]);
    }

    public function change(Request $request, AcademicSession $session): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:500']]);

        try {
            $this->lifecycle->transition($session, $data['to'], $request->user(), $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('success', "{$session->name} is now " . $this->lifecycle->label($data['to']) . '.');
    }
}
