<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AssessmentAssignment;
use App\Models\AssessmentScheme;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Services\AssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** Academic Setup → Assessment Schemes: build schemes of assessments and decide which courses use them. */
class AssessmentSchemeController extends Controller
{
    public function __construct(private AssessmentService $assessments)
    {
        $this->middleware('permission:View assessment schemes')->only('index', 'check');
        $this->middleware('permission:Create assessment schemes')->only('create', 'store', 'duplicate');
        $this->middleware('permission:Edit assessment schemes')->only('edit', 'update');
        $this->middleware('permission:Delete assessment schemes')->only('destroy');
        $this->middleware('permission:Assign assessment schemes')->only('assign', 'unassign');
    }

    public function index(Request $request): View
    {
        $schemes = AssessmentScheme::with('items')->withCount(['sheets', 'assignments'])->orderByDesc('is_active')->orderBy('name')->get();
        $assignments = AssessmentAssignment::with('scheme', 'session')->get()
            ->sortBy(fn ($a) => [array_search($a->scope_type, array_keys(AssessmentAssignment::SCOPES), true), $a->scopeLabel(), $a->session?->name ?? ''])->values();

        $check = null;
        if ($request->filled('course_id')) {
            $course = Course::find($request->integer('course_id'));
            if ($course) {
                [$scheme, $from] = $this->assessments->resolve($course, $request->integer('session_id') ?: AcademicSession::where('is_current', true)->value('id'));
                $check = ['course' => $course, 'scheme' => $scheme, 'from' => $from];
            }
        }

        return view('academics.assessments.index', [
            'pagetitle' => 'Assessment Schemes',
            'schemes' => $schemes,
            'assignments' => $assignments,
            'check' => $check,
            'sessions' => AcademicSession::orderByDesc('name')->pluck('name', 'id'),
            'currentSession' => AcademicSession::where('is_current', true)->value('id'),
            'faculties' => Faculty::orderBy('name')->pluck('name', 'id'),
            'departments' => Department::orderBy('name')->pluck('name', 'id'),
            'courses' => Course::orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} · {$c->title}"]),
        ]);
    }

    public function create(): View
    {
        $scheme = new AssessmentScheme(['is_active' => true, 'ca_total' => 40, 'exam_total' => 60]);
        $scheme->setRelation('items', collect([
            new \App\Models\AssessmentItem(['name' => 'CA 1', 'code' => 'CA1', 'component' => 'ca', 'max_score' => 10]),
            new \App\Models\AssessmentItem(['name' => 'CA 2', 'code' => 'CA2', 'component' => 'ca', 'max_score' => 15]),
            new \App\Models\AssessmentItem(['name' => 'CA 3', 'code' => 'CA3', 'component' => 'ca', 'max_score' => 15]),
            new \App\Models\AssessmentItem(['name' => 'Exam question 1', 'code' => 'Q1', 'component' => 'exam', 'max_score' => 20]),
            new \App\Models\AssessmentItem(['name' => 'Exam question 2', 'code' => 'Q2', 'component' => 'exam', 'max_score' => 20]),
            new \App\Models\AssessmentItem(['name' => 'Exam question 3', 'code' => 'Q3', 'component' => 'exam', 'max_score' => 20]),
        ]));

        return view('academics.assessments.form', ['pagetitle' => 'New assessment scheme', 'scheme' => $scheme, 'locked' => false]);
    }

    public function edit(AssessmentScheme $scheme): View
    {
        return view('academics.assessments.form', ['pagetitle' => 'Edit ' . $scheme->name, 'scheme' => $scheme->load('items'), 'locked' => $scheme->isLocked()]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'ca_total' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'exam_total' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items' => ['required', 'array', 'max:30'],
            'items.*.name' => ['nullable', 'string', 'max:80'],
            'items.*.code' => ['nullable', 'string', 'max:20'],
            'items.*.component' => ['nullable', Rule::in(['ca', 'exam'])],
            'items.*.max_score' => ['nullable', 'numeric'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validated($request);
        try {
            $scheme = $this->assessments->save(null, $d, $d['items'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        return redirect()->route('academics.assessments.index')->with('success', "{$scheme->name} saved. Assign it below to put it to use.");
    }

    public function update(Request $request, AssessmentScheme $scheme): RedirectResponse
    {
        $d = $this->validated($request);
        try {
            $this->assessments->save($scheme, $d, $d['items'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        return redirect()->route('academics.assessments.index')->with('success', "{$scheme->name} saved.");
    }

    public function duplicate(Request $request, AssessmentScheme $scheme): RedirectResponse
    {
        $copy = $this->assessments->duplicate($scheme->load('items'), $request->user());

        return redirect()->route('academics.assessments.edit', $copy->id)->with('success', 'Copy made. Change it and save.');
    }

    public function destroy(AssessmentScheme $scheme): RedirectResponse
    {
        if ($why = $this->assessments->blockDelete($scheme)) {
            return back()->with('error', $why);
        }
        $scheme->delete();

        return back()->with('success', 'Scheme deleted.');
    }

    public function assign(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'assessment_scheme_id' => ['required', 'exists:assessment_schemes,id'],
            'scope_type' => ['required', Rule::in(array_keys(AssessmentAssignment::SCOPES))],
            'faculty_id' => ['nullable', 'required_if:scope_type,faculty', 'exists:faculties,id'],
            'department_id' => ['nullable', 'required_if:scope_type,department', 'exists:departments,id'],
            'course_id' => ['nullable', 'required_if:scope_type,course', 'exists:courses,id'],
            'academic_session_id' => ['nullable', 'exists:academic_sessions,id'],
        ]);
        $scopeId = match ($d['scope_type']) {
            'faculty' => $d['faculty_id'] ?? null, 'department' => $d['department_id'] ?? null, 'course' => $d['course_id'] ?? null, default => null,
        };

        try {
            $a = $this->assessments->assign(AssessmentScheme::findOrFail($d['assessment_scheme_id']), $d['scope_type'], $scopeId ? (int) $scopeId : null, $d['academic_session_id'] ?? null, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('success', "{$a->scheme->name} now applies to: {$a->scopeLabel()}" . ($a->session ? " ({$a->session->name})" : ' (every session)') . '. Sheets that already have marks keep their scheme.');
    }

    public function unassign(AssessmentAssignment $assignment): RedirectResponse
    {
        $this->assessments->unassign($assignment);

        return back()->with('success', 'Assignment removed. Open sheets without marks now follow the next scheme that applies.');
    }
}
