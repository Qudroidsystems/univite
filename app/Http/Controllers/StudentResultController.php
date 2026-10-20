<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\ResultService;
use Illuminate\View\View;

/** Published results, GPA and CGPA: a student's own, or any student's for staff with permission. */
class StudentResultController extends Controller
{
    public function __construct(private ResultService $results, private \App\Services\ResultComplaintService $complaints, private \App\Services\Access\ScopedAccess $access, private \App\Services\Fees\FeeAccess $fees)
    {
    }

    public function mine(): View
    {
        $student = auth()->user()->student ?? abort(403, 'This page is for students.');
        if ($reason = $this->fees->blocker($student, 'results')) {
            return view('fees.blocked', ['pagetitle' => 'My results', 'title' => 'Your results are held', 'reason' => $reason, 'standing' => $this->fees->standing($student)]);
        }

        return $this->render($student, 'My results', true);
    }

    public function transcript(Student $student): View
    {
        abort_unless($this->access->scope(auth()->user(), 'View student results')->coversStudent($student), 403);

        return $this->render($student, 'Transcript', false);
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
