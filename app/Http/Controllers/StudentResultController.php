<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\ResultService;
use Illuminate\View\View;

/** Published results, GPA and CGPA: a student's own, or any student's for staff with permission. */
class StudentResultController extends Controller
{
    public function __construct(private ResultService $results, private \App\Services\ResultComplaintService $complaints)
    {
    }

    public function mine(): View
    {
        $student = auth()->user()->student ?? abort(403, 'This page is for students.');

        return $this->render($student, 'My results', true);
    }

    public function transcript(Student $student): View
    {
        abort_unless(auth()->user()->can('View student results'), 403);

        return $this->render($student, 'Transcript', false);
    }

    public function broadsheet(\Illuminate\Http\Request $request): View
    {
        abort_unless(auth()->user()->can('View student results'), 403);
        $session = $request->query('session_id', \App\Models\AcademicSession::current()?->id);
        $type = $request->query('semester_type_id', \App\Models\SemesterType::orderBy('sort_order')->value('id'));
        $programme = $request->query('programme_id');
        $level = $request->query('level');
        $sheet = ($programme && $level && $session && $type) ? $this->results->broadsheet((int) $programme, (int) $level, (int) $session, (int) $type) : null;

        return view('results.broadsheet', [
            'pagetitle' => 'Broadsheet', 'sheet' => $sheet, 'f' => compact('session', 'type', 'programme', 'level'),
            'programmes' => \App\Models\Programme::orderBy('name')->pluck('name', 'id'), 'sessions' => \App\Models\AcademicSession::orderByDesc('name')->pluck('name', 'id'),
            'types' => \App\Models\SemesterType::orderBy('sort_order')->pluck('name', 'id'), 'levels' => \App\Support\Levels::options(),
        ]);
    }

    private function render(Student $student, string $title, bool $mine): View
    {
        $complaints = $mine ? \App\Models\ResultComplaint::where('student_id', $student->id)->latest('id')->get()->groupBy('course_result_id') : collect();

        return view('results.transcript', [
            'pagetitle' => $title, 'student' => $student->load('programme.department.faculty', 'entrySession'),
            'mine' => $mine, 't' => $this->results->transcript($student), 'complaints' => $complaints,
            'canQuery' => $mine ? fn ($r) => $this->complaints->blocker($student, $r) === null : fn () => false,
        ]);
    }
}
