<?php

namespace App\Http\Controllers\TeachingPractice;

use App\Data\TeachingPractice\VisitScore;
use App\Http\Controllers\Controller;
use App\Models\TpPosting;
use App\Models\TpSupervisor;
use App\Services\TeachingPractice\TeachingPracticeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** A supervisor's students and the lesson assessment form. Coordinators with "Record teaching practice scores" can mark anyone. */
class SupervisionController extends Controller
{
    public function __construct(private TeachingPracticeService $tp)
    {
    }

    public function index(Request $request): View
    {
        $mine = TpSupervisor::with('exercise', 'school')->where('user_id', $request->user()->id)->get();
        abort_if($mine->isEmpty() && ! $request->user()->can('Record teaching practice scores'), 403, 'You are not supervising teaching practice.');
        $postings = TpPosting::with('student', 'school', 'assessments', 'exercise')->where('status', 'posted')
            ->where(fn ($q) => $mine->each(fn ($s) => $q->orWhere(fn ($w) => $w->where('tp_exercise_id', $s->tp_exercise_id)->where('tp_school_id', $s->tp_school_id))))
            ->when($mine->isEmpty(), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('tp_exercise_id')->orderBy('tp_school_id')->get();

        return view('tp.supervise', ['pagetitle' => 'My teaching practice students', 'mine' => $mine, 'postings' => $postings]);
    }

    public function assessForm(Request $request, TpPosting $posting): View
    {
        $this->authorizeFor($request, $posting);

        return view('tp.assess', ['pagetitle' => 'Lesson assessment', 'posting' => $posting->load('student', 'school', 'exercise', 'assessments.supervisor'),
            'visit' => (int) $request->query('visit', min($posting->exercise->visits_required, $posting->assessments()->count() + 1))]);
    }

    public function assess(Request $request, TpPosting $posting): RedirectResponse
    {
        $this->authorizeFor($request, $posting);
        try {
            $a = $this->tp->assess($posting, VisitScore::fromArray($request->validate(VisitScore::rules())), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tp.supervise')->with('success', 'Visit ' . $a->visit_no . ' for ' . $posting->student->full_name . ' saved: ' . $a->percent . '%.');
    }

    private function authorizeFor(Request $request, TpPosting $posting): void
    {
        abort_unless($request->user()->can('Record teaching practice scores') || $this->tp->supervises($request->user(), $posting), 403);
    }
}
