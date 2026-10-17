<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\Department;
use App\Models\SemesterType;
use App\Models\StandingWaiver;
use App\Models\Student;
use App\Services\ResultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A department's semester result summary (passed / carry-overs / probation / advised to withdraw), and letting
 * a student who was advised to withdraw register for a session. Open to "View student results", and to a
 * department's head, administrators and dean for their own departments.
 */
class ResultSummaryController extends Controller
{
    public function __construct(private ResultService $results, private \App\Services\OperationAudit $audit, private \App\Services\RegistrationService $registration, private \App\Services\Results\CarryoverWaivers $waivers, private \App\Services\Access\ScopedAccess $access, private \App\Services\Access\AcademicRuleAccess $rules)
    {
    }

    private function departments(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        $all = Department::orderBy('name')->get();

        $view = $this->access->scope($user, 'View student results');
        $waive = $this->access->scope($user, 'Waive carryover courses');

        return $all->filter(fn ($d) => $view->coversDepartment($d->id) || $waive->coversDepartment($d->id) || $this->rules->canEdit($user, 'department', $d->id))->values();
    }

    public function index(Request $request): View
    {
        $departments = $this->departments();
        abort_if($departments->isEmpty(), 403);
        $dept = $departments->firstWhere('id', (int) $request->query('department_id')) ?? ($request->filled('department_id') ? abort(403) : null);
        $session = (int) $request->query('session_id', AcademicSession::current()?->id);
        $type = (int) $request->query('semester_type_id', SemesterType::orderBy('sort_order')->value('id'));
        $level = $request->filled('level') ? (int) $request->query('level') : null;

        return view('results.summary', [
            'canAllow' => fn (Student $student) => $this->rules->canEdit(auth()->user(), 'programme', $student->programme_id),
            'pagetitle' => 'Result summary', 'departments' => $departments->pluck('name', 'id'), 'dept' => $dept,
            'groups' => $dept && $session && $type ? $this->results->summary($dept->id, $level, $session, $type) : null,
            'f' => compact('session', 'type', 'level'), 'sessions' => AcademicSession::orderByDesc('name')->pluck('name', 'id'),
            'types' => SemesterType::orderBy('sort_order')->pluck('name', 'id'), 'levels' => \App\Support\Levels::options(),
            'waivers' => StandingWaiver::where('academic_session_id', AcademicSession::current()?->id)->pluck('student_id')->flip(),
            'current' => AcademicSession::current(),
            'canWaive' => fn ($student) => $this->waivers->canManage(auth()->user(), $student),
        ]);
    }

    /** Let a student advised to withdraw register in the current session. */
    public function waive(Request $request, Student $student): RedirectResponse
    {
        abort_unless($this->rules->canEdit($request->user(), 'programme', $student->programme_id), 403);
        $d = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $session = AcademicSession::current() ?? abort(422, 'No session is current.');
        $waiver = StandingWaiver::updateOrCreate(['student_id' => $student->id, 'academic_session_id' => $session->id], ['note' => $d['note'], 'granted_by' => $request->user()->id]);
        $this->audit->note('standing_waiver', 'results', $request->user(), 'registration_allowed', $waiver, null,
            ['student' => $student->full_name, 'session' => $session->name, 'standing' => $this->registration->standing($student)], $d['note'], $session->id);

        return back()->with('success', "{$student->full_name} may register in {$session->name}.");
    }
}
