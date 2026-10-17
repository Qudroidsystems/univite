<?php

namespace App\Http\Controllers;

use App\Data\Academics\DepartmentLeaders;
use App\Data\Results\Score;
use App\Models\Course;
use App\Models\ResultSheet;
use App\Services\RegistrationService;
use App\Services\ResultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

/** Lecturers' score sheets and the approval chain. Access is by course assignment or by permission, checked per sheet. */
class ResultSheetController extends Controller
{
    public function __construct(private ResultService $results, private RegistrationService $registration, private \App\Services\AssessmentService $assessments)
    {
    }

    private function mayView(ResultSheet $sheet): bool
    {
        $u = auth()->user();

        return $this->results->canEnter($u, $sheet) || $u->can('View result sheets') || $this->results->canReview($u, $sheet);
    }

    private function authorizeEntry(ResultSheet $sheet): void
    {
        abort_unless($this->results->canEnter(auth()->user(), $sheet), 403, 'You are not assigned to this course.');
    }

    public function index(Request $request): View
    {
        $user = auth()->user();
        $ctx = $this->registration->context();
        $mine = $ctx ? $user->scoringAssignments()->with('course.department', 'course.studyCategory')
            ->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id)->get() : collect();

        $sheets = ResultSheet::with('course', 'session', 'semesterType', 'stage')->withCount('results');
        $status = $request->query('status', 'submitted');
        $all = null;
        if ($user->can('View result sheets')) {
            $all = (clone $sheets)->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest('updated_at')->paginate(25)->withQueryString();
        }
        // Sheets waiting at a step this person signs (heads see their department's or faculty's courses).
        $waiting = (clone $sheets)->with('course.department')->where('status', 'submitted')->oldest('submitted_at')->get()
            ->filter(fn ($s) => $this->results->canApprove($user, $s))->values();
        $mySheets = (clone $sheets)->whereIn('course_id', $user->scoringAssignments()->pluck('course_id'))->latest('updated_at')->limit(30)->get()
            ->filter(fn ($s) => $this->results->canEnter($user, $s));

        // What each of my courses is marked on this semester: the opened sheet's scheme, or the one that applies.
        $current = $ctx ? ResultSheet::with('scheme.items')->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id)
            ->whereIn('course_id', $mine->pluck('course_id'))->get()->keyBy('course_id') : collect();
        $schemes = $mine->mapWithKeys(function ($a) use ($current, $ctx) {
            $sheet = $current->get($a->course_id);
            $scheme = $sheet ? $sheet->scheme : $this->assessments->resolve($a->course, $ctx?->session->id)[0];

            return [$a->course_id => ['sheet' => $sheet, 'scheme' => $scheme]];
        });

        // Each course's department with its head of department and dean, shown on the course's row.
        $leaders = $mine->pluck('course.department')->filter()->unique('id')
            ->mapWithKeys(fn ($d) => [$d->id => DepartmentLeaders::of($d)]);

        return view('results.index', [
            'pagetitle' => 'Results', 'ctx' => $ctx, 'leaders' => $leaders, 'mine' => $mine, 'mySheets' => $mySheets, 'all' => $all, 'status' => $status, 'schemes' => $schemes, 'waiting' => $waiting,
            'caMax' => ResultService::caMax(), 'examMax' => ResultService::examMax(),
            'counts' => ResultSheet::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    /** Open (creating if needed) the sheet for an assigned course this semester. */
    public function open(Course $course): RedirectResponse
    {
        $ctx = $this->registration->context() ?? abort(404, 'No semester is running.');
        $sheet = $this->results->sheet($course->id, $ctx->session->id, $ctx->type->id);
        $this->authorizeEntry($sheet);

        return redirect()->route('results.sheets.show', $sheet->id);
    }

    public function show(ResultSheet $sheet): View
    {
        abort_unless($this->mayView($sheet), 403);
        if ($sheet->isEditable() && $this->results->canEnter(auth()->user(), $sheet)) {
            $this->results->syncRows($sheet);
        }
        $sheet->load('course.department', 'session', 'semesterType', 'stage', 'events.user', 'results.student', 'results.assessmentScores', 'scheme.items');
        [$caMax, $examMax] = ResultService::limits($sheet);

        return view('results.show', [
            'pagetitle'  => $sheet->course->code . ' results',
            'sheet'      => $sheet,
            'rows'       => $sheet->results->sortBy(fn ($r) => $r->student->last_name . $r->student->first_name),
            'canEnter'   => $this->results->canEnter(auth()->user(), $sheet),
            'canApprove' => $this->results->canApprove(auth()->user(), $sheet),
            'canReview'  => $this->results->canReview(auth()->user(), $sheet),
            'canCorrect' => $this->results->canCorrect(auth()->user(), $sheet),
            'caMax'      => $caMax, 'examMax' => $examMax,
            'scheme'     => $sheet->scheme,
            'schemes'    => ($sheet->isEditable() && (auth()->user()->can('Assign assessment schemes') || auth()->user()->can('Enter results for any course')))
                ? \App\Models\AssessmentScheme::where('is_active', true)->orderBy('name')->get() : collect(),
            'missing'    => $sheet->isEditable() ? $this->results->incomplete($sheet) : 0,
            'complaints' => \App\Models\ResultComplaint::with('student')->whereIn('course_result_id', $sheet->results->pluck('id'))->latest()->get(),
        ]);
    }

    public function save(Request $request, ResultSheet $sheet): RedirectResponse
    {
        $this->authorizeEntry($sheet);
        $data = $request->validate(['scores' => ['required', 'array'], 'scores.*.ca' => ['nullable'], 'scores.*.exam' => ['nullable'], 'scores.*.items' => ['nullable', 'array'], 'scores.*.total' => ['nullable']]);

        try {
            $problems = $this->results->saveScores($sheet, Score::many($data['scores']), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $problems
            ? back()->with('warning', 'Saved, except: ' . implode(' ', $problems))
            : back()->with('success', 'Scores saved.');
    }

    /** An approver's changes to the scores of a sheet they are approving or approved (each score unlocked one by one). */
    public function correct(Request $request, ResultSheet $sheet): RedirectResponse
    {
        abort_unless($this->results->canCorrect($request->user(), $sheet), 403);
        $data = $request->validate(['scores' => ['required', 'array'], 'scores.*.ca' => ['nullable'], 'scores.*.exam' => ['nullable'], 'scores.*.items' => ['nullable', 'array'], 'scores.*.total' => ['nullable'],
            'note' => ['nullable', 'string', 'max:400']]);

        try {
            $out = $this->results->correct($sheet, Score::many($data['scores']), $request->user(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        if ($out['problems']) {
            return back()->with('warning', ($out['changed'] ? "{$out['changed']} score(s) changed, except: " : 'Nothing changed: ') . implode(' ', $out['problems']));
        }

        return back()->with($out['changed'] ? 'success' : 'warning', $out['changed'] ? "Scores changed for {$out['changed']} student(s). The change is recorded on the sheet's history." : 'No score was changed.');
    }

    public function upload(Request $request, ResultSheet $sheet): RedirectResponse
    {
        $this->authorizeEntry($sheet);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:4096']]);

        try {
            $out = $this->results->import($sheet, $request->file('file'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = $out->saved . ' row(s) read.';

        return $out->problems
            ? back()->with('warning', $msg . ' Not saved: ' . $out->summary())
            : back()->with('success', $msg . ' Scores saved.');
    }

    /** The sheet as an Excel file (or ?format=csv), detailed or ?type=total, that the lecturer can fill in and upload again. */
    public function template(Request $request, ResultSheet $sheet, \App\Services\ScoreSheetFile $file): Response
    {
        abort_unless($this->mayView($sheet), 403);
        $csv = $request->query('format') === 'csv';
        $type = $request->query('type') === 'total' ? 'total' : 'detailed';

        return response($csv ? $file->csv($sheet, $type) : $file->xlsx($sheet, $type), 200, [
            'Content-Type' => $csv ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $file->filename($sheet, $csv ? 'csv' : 'xlsx', $type) . '"',
        ]);
    }

    public function submit(Request $request, ResultSheet $sheet): RedirectResponse
    {
        $this->authorizeEntry($sheet);

        try {
            $this->results->submit($sheet, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Scores submitted for approval.');
    }

    /** Correct a published score (reason required, logged on the sheet). */
    public function amend(Request $request, \App\Models\CourseResult $result): RedirectResponse
    {
        abort_unless($request->user()->can('Amend published results'), 403);
        $d = $request->validate(['ca' => ['required_without:items', 'nullable', 'numeric'], 'exam' => ['required_without:items', 'nullable', 'numeric'],
            'items' => ['nullable', 'array'], 'items.*' => ['nullable', 'numeric'], 'reason' => ['required', 'string', 'max:400']]);

        try {
            $this->results->amend($result, $d['ca'] ?? null, $d['exam'] ?? null, $d['reason'], $request->user(), items: $d['items'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Result corrected and logged.');
    }

    /** Put a draft sheet on an assessment scheme (or back on plain CA / exam), keeping marks already entered. */
    public function scheme(Request $request, ResultSheet $sheet, \App\Services\AssessmentService $assessments): RedirectResponse
    {
        abort_unless($request->user()->can('Assign assessment schemes') || $request->user()->can('Enter results for any course'), 403);
        $d = $request->validate(['assessment_scheme_id' => ['nullable', 'exists:assessment_schemes,id']]);

        try {
            $scheme = ($d['assessment_scheme_id'] ?? null) ? \App\Models\AssessmentScheme::find($d['assessment_scheme_id']) : null;
            $moved = $assessments->convert($sheet, $scheme, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->results->log($sheet, $request->user(), 'scheme', null, $sheet->fresh()->scheme?->name ?? 'Plain CA and exam');

        return back()->with('success', 'Assessments changed.' . ($moved ? " The CA and exam already entered for {$moved} student(s) were spread over the new assessments; check them." : ''));
    }

    public function decide(Request $request, ResultSheet $sheet): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,return,publish'], 'note' => ['nullable', 'string', 'max:500']]);
        $user = $request->user();
        abort_if($data['decision'] === 'publish' && ! $user->can('Publish results'), 403);

        try {
            match ($data['decision']) {
                'approve' => $this->results->approve($sheet, $user, $data['note'] ?? null),
                'return'  => $this->results->returnToLecturer($sheet, $user, $data['note'] ?? null),
                'publish' => $this->results->publish($sheet, $user),
            };
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', ['approve' => 'Approved.', 'return' => 'Returned to the lecturer.', 'publish' => 'Results published. Students can see them now.'][$data['decision']]);
    }
}
