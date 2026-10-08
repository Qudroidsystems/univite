<?php

namespace App\Http\Controllers;

use App\Models\ClearanceItem;
use App\Models\Student;
use App\Services\ClearanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** A student's own clearance: live checklist, re-submitting rejected items, notifications and the slip. */
class StudentClearanceController extends Controller
{
    public function __construct(private ClearanceService $clearance)
    {
    }

    private function student(): Student
    {
        $student = auth()->user()->student ?? abort(403, 'This page is for students.');
        abort_unless($student->needs_onboarding, 404);

        return $student;
    }

    public function index(): View
    {
        $student = $this->student()->load('programme');
        $rows = $this->clearance->checklist($student);
        $user = auth()->user();

        $view = view('clearance.mine', [
            'pagetitle'     => 'My clearance',
            'student'       => $student->fresh(),
            'rows'          => $rows,
            'notifications' => $user->notifications()->latest()->limit(15)->get(),
        ]);
        $user->unreadNotifications->markAsRead();

        return $view;
    }

    public function resubmit(Request $request, ClearanceItem $item): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        try {
            $this->clearance->resubmit($this->student(), $item, $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sent back for another look.');
    }

    public function slip(): View
    {
        $student = $this->student()->load('programme.department.faculty', 'entrySession');
        abort_unless($student->cleared_at, 404, 'You are not fully cleared yet.');

        return view('clearance.slip', ['pagetitle' => 'Clearance slip', 'student' => $student, 'rows' => $this->clearance->checklist($student), 'mine' => true]);
    }
}
