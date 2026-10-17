<?php

namespace App\Http\Controllers;

use App\Data\Results\ComputeQuery;
use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\SemesterType;
use App\Models\StudyCategory;
use App\Services\Results\DepartmentMasterSheet;
use App\Services\Results\ResultComputation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Compute Results: the Head of Department checks the submitted scores and computes the department's results. */
class ComputeResultsController extends Controller
{
    public function __construct(private ResultComputation $computation, private DepartmentMasterSheet $sheets)
    {
        $this->middleware('scoped:' . ResultComputation::PERMISSION);
    }

    private function query(Request $request): ComputeQuery
    {
        $q = ComputeQuery::fromRequest($request);
        if ($q->department) {
            abort_unless($this->computation->scope($request->user())->coversDepartment($q->department->id), 403, 'You can compute results only for your own department.');
        }

        return $q;
    }

    public function index(Request $request): View
    {
        $q = $this->query($request);
        $departments = $this->computation->departmentsFor($request->user());
        if (! $q->department && $departments->count() === 1) {
            $q = new ComputeQuery($departments->first(), $q->category, $q->course, $q->level, $q->session, $q->semesterType);
        }

        return view('results.compute', [
            'pagetitle' => 'Compute Results',
            'q' => $q,
            'overview' => $q->complete() ? $this->computation->overview($q) : null,
            'departments' => $departments,
            'categories' => StudyCategory::orderBy('name')->get(),
            'courses' => $q->department ? Course::where('department_id', $q->department->id)->orderBy('code')->get() : collect(),
            'sessions' => AcademicSession::orderByDesc('name')->get(),
            'types' => SemesterType::orderBy('sort_order')->get(),
            'levels' => \App\Support\Levels::options(),
            'pdf' => DepartmentMasterSheet::pdfAvailable(),
        ]);
    }

    public function compute(Request $request): RedirectResponse
    {
        $q = $this->query($request);
        if (! $q->complete()) {
            return back()->with('error', 'Choose a department, level, session and semester first.');
        }
        $outcome = $this->computation->compute($q, $request->user());

        return redirect()->route('results.compute', $q->params())->with('success', $outcome->message());
    }

    public function masterSheet(Request $request, string $format): Response|RedirectResponse
    {
        $q = $this->query($request);
        abort_unless($q->complete(), 422, 'Choose a department, level, session and semester.');
        try {
            $sheet = $this->sheets->build($q);
            $name = Str::slug('master marks sheet ' . $q->department->code . ' ' . ($q->category?->code ?? '') . " {$q->level} {$q->session->name} {$q->semesterType->name}");

            return match ($format) {
                'excel' => response($this->sheets->excel($sheet), 200, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="' . $name . '.xlsx"',
                ]),
                'pdf' => response($this->sheets->pdf($sheet), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $name . '.pdf"',
                ]),
                default => response($this->sheets->html($sheet)),
            };
        } catch (RuntimeException $e) {
            return redirect()->route('results.compute', $q->params())->with('error', $e->getMessage());
        }
    }
}
