<?php

namespace App\Http\Controllers;

use App\Models\CourseResult;
use App\Models\ResultComplaint;
use App\Services\ResultComplaintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Students query a published result from My Results; staff answer the queries here. */
class ResultComplaintController extends Controller
{
    public function __construct(private ResultComplaintService $complaints)
    {
    }

    public function store(Request $request, CourseResult $result): RedirectResponse
    {
        $student = $request->user()->student ?? abort(403, 'This page is for students.');
        $d = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        try {
            $this->complaints->lodge($student, $result, $d['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Your query has been sent. The answer will appear on this page.');
    }

    public function index(Request $request): View
    {
        abort_unless($request->user()->can('View result complaints'), 403);
        $status = $request->query('status', 'open');
        $list = ResultComplaint::with('student', 'result.course', 'result.session', 'result.semesterType', 'result.sheet', 'handler')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->query('q'), fn ($q, $s) => $q->whereHas('student', fn ($w) => $w->where('matric_number', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")))
            ->latest()->paginate(25)->withQueryString();

        return view('results.complaints', [
            'pagetitle' => 'Result queries', 'list' => $list, 'status' => $status,
            'counts' => ResultComplaint::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function respond(Request $request, ResultComplaint $complaint): RedirectResponse
    {
        abort_unless($request->user()->can('Respond to result complaints'), 403);
        $d = $request->validate([
            'decision' => ['required', 'in:upheld,rejected'], 'response' => ['required', 'string', 'max:2000'],
            'ca' => ['nullable', 'numeric'], 'exam' => ['nullable', 'numeric'],
        ]);

        try {
            $this->complaints->respond($complaint, $request->user(), $d['decision'], $d['response'], $d['ca'] ?? null, $d['exam'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Answer sent to the student.');
    }
}
