<?php

namespace App\Http\Controllers\TeachingPractice;

use App\Http\Controllers\Controller;
use App\Models\TpPosting;
use App\Services\TeachingPractice\TeachingPracticeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** A student's teaching practice: choosing schools, the posting, the letter and the visits. */
class MyTeachingPracticeController extends Controller
{
    public function __construct(private TeachingPracticeService $tp)
    {
    }

    public function show(Request $request): View
    {
        $student = $request->user()->student;
        abort_unless($student !== null, 403, 'This page is for students.');

        return view('tp.mine', ['pagetitle' => 'Teaching Practice', 'tpService' => $this->tp,
            'postings' => TpPosting::with('exercise.schools', 'school', 'assessments')->where('student_id', $student->id)->where('status', '!=', 'withdrawn')->latest('id')->get()]);
    }

    public function choose(Request $request, TpPosting $posting): RedirectResponse
    {
        abort_unless($posting->student_id === $request->user()->student?->id, 404);
        $d = $request->validate(['choices' => ['required', 'array'], 'choices.*' => ['nullable', 'integer'], 'subject' => ['nullable', 'string', 'max:100']]);
        try {
            $this->tp->choose($posting, $d['choices'], $d['subject'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Your choices are saved.');
    }

    public function letter(Request $request, TpPosting $posting): View
    {
        abort_unless($posting->student_id === $request->user()->student?->id && $posting->status === 'posted', 404);

        return view('tp.letter', ['pagetitle' => 'Posting letter', 'posting' => $posting->load('exercise', 'school', 'student.programme')]);
    }
}
