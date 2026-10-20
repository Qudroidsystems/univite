<?php

namespace App\Http\Controllers\Olevel;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Programme;
use App\Models\Student;
use App\Models\StudentOlevelSitting;
use App\Services\Access\ScopedAccess;
use App\Services\Olevel\OlevelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Staff confirming students' O Level results: the uploaded result beside the grades the student typed and the grades
 * read from it, then confirm (choosing which grades to keep) or return it. Heads and others holding "Verify O Level
 * results" for a department see only that department's students.
 */
class OlevelVerificationController extends Controller
{
    public function __construct(private OlevelService $olevel, private ScopedAccess $access)
    {
    }

    private function canSee(Request $request): \App\Data\Access\AccessScope
    {
        $view = $this->access->scope($request->user(), 'View O Level results');
        $verify = $this->access->scope($request->user(), 'Verify O Level results');
        if ($view->all || $verify->all) {
            return \App\Data\Access\AccessScope::everything();
        }

        return new \App\Data\Access\AccessScope(false, array_values(array_unique([...$view->departmentIds, ...$verify->departmentIds])));
    }

    private function mayVerify(Request $request, Student $student): bool
    {
        return $this->access->scope($request->user(), 'Verify O Level results')->coversStudent($student);
    }

    private function authorise(Request $request, Student $student): void
    {
        abort_unless($this->canSee($request)->coversStudent($student->loadMissing('programme')), 403);
    }

    public function index(Request $request): View
    {
        $scope = $this->canSee($request);
        abort_if($scope->isEmpty(), 403);
        $status = $request->query('status', 'submitted');
        $q = StudentOlevelSitting::with('student.programme.department', 'results', 'reviewer')
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->whereHas('student', fn ($s) => $scope->students($s)
                ->when($request->filled('programme_id'), fn ($p) => $p->where('programme_id', $request->query('programme_id')))
                ->when($request->filled('department_id'), fn ($p) => $p->whereHas('programme', fn ($x) => $x->where('department_id', $request->query('department_id'))))
                ->when(trim((string) $request->query('q')) !== '', fn ($p) => $p->where(fn ($x) => $x->where('matric_number', 'like', '%' . $request->query('q') . '%')
                    ->orWhere('provisional_matric', 'like', '%' . $request->query('q') . '%')->orWhere('last_name', 'like', '%' . $request->query('q') . '%')->orWhere('first_name', 'like', '%' . $request->query('q') . '%'))))
            ->orderByRaw("case status when 'submitted' then 0 when 'rejected' then 1 when 'draft' then 2 else 3 end")->latest('submitted_at');
        $counts = StudentOlevelSitting::whereHas('student', fn ($s) => $scope->students($s))->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('olevel.index', [
            'pagetitle' => 'O Level Verification', 'rows' => $q->paginate(25)->withQueryString(), 'status' => $status, 'counts' => $counts,
            'departments' => Department::orderBy('name')->get()->filter(fn ($d) => $scope->coversDepartment($d->id))->pluck('name', 'id'),
            'programmes' => Programme::orderBy('name')->get()->filter(fn ($p) => $scope->coversDepartment($p->department_id))->pluck('name', 'id'),
        ]);
    }

    public function show(Request $request, StudentOlevelSitting $sitting): View
    {
        $student = $sitting->student;
        $this->authorise($request, $student);
        $all = $student->olevelSittings()->with('results', 'reviewer')->get();
        $sitting->load('results');

        return view('olevel.show', [
            'pagetitle' => 'O Level · ' . $student->full_name, 'sitting' => $sitting, 'student' => $student->load('programme.department.faculty'), 'all' => $all,
            'check' => $this->olevel->evaluate($student),
            'withStudent' => $this->olevel->evaluate($student, $all->whereIn('status', ['submitted', 'confirmed'])->values(), 'student'),
            'withExtracted' => $sitting->extraction_status === 'done' ? $this->olevel->evaluate($student, $all->whereIn('status', ['submitted', 'confirmed'])->values(), 'extracted') : null,
            'requirement' => $this->olevel->requirementFor($student->programme),
            'canVerify' => $this->mayVerify($request, $student), 'aiOn' => $this->olevel->aiAvailable(), 'grades' => StudentOlevelSitting::GRADES,
        ]);
    }

    public function confirm(Request $request, StudentOlevelSitting $sitting): RedirectResponse
    {
        abort_unless($this->mayVerify($request, $sitting->student), 403);
        $d = $request->validate([
            'source' => ['required', Rule::in(['student', 'extracted', 'mixed'])],
            'grades' => ['nullable', 'array'], 'grades.*' => ['nullable', Rule::in(StudentOlevelSitting::GRADES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $this->olevel->confirm($sitting, $request->user(), $d['source'], array_map(fn ($g) => $g ?: null, $d['grades'] ?? []), $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $check = $this->olevel->evaluate($sitting->student->fresh());

        return back()->with($check->met || ! $check->requirement ? 'success' : 'warning', 'Sitting confirmed. ' . $check->status() . ($check->problems ? ' ' . implode(' ', $check->problems) : ''));
    }

    public function reject(Request $request, StudentOlevelSitting $sitting): RedirectResponse
    {
        abort_unless($this->mayVerify($request, $sitting->student), 403);
        $d = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        try {
            $this->olevel->reject($sitting, $request->user(), $d['note']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Returned to the student to correct.');
    }

    public function read(Request $request, StudentOlevelSitting $sitting): RedirectResponse
    {
        abort_unless($this->mayVerify($request, $sitting->student), 403);
        try {
            $sitting = $this->olevel->read($sitting, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($sitting->extraction_status === 'done' ? 'success' : 'error', $sitting->extraction_status === 'done' ? 'The uploaded result was read again.' : ($sitting->extraction_note ?: 'It could not be read.'));
    }

    public function allowRegistration(Request $request, Student $student): RedirectResponse
    {
        abort_unless($this->mayVerify($request, $student), 403);
        $d = $request->validate(['allow' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        try {
            $this->olevel->override($student, $request->user(), (bool) $d['allow'], $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $d['allow'] ? 'The student may register courses while their O Level is sorted out.' : 'The O Level requirement applies to the student again.');
    }

    public function document(Request $request, StudentOlevelSitting $sitting)
    {
        $this->authorise($request, $sitting->student);
        abort_unless($sitting->document_path && Storage::disk('local')->exists($sitting->document_path), 404);

        return response()->file(Storage::disk('local')->path($sitting->document_path), ['Content-Disposition' => 'inline; filename="' . addslashes((string) $sitting->document_name) . '"']);
    }
}
