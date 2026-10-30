<?php

namespace App\Http\Controllers\Fees;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Department;
use App\Models\Programme;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Services\Access\ScopedAccess;
use App\Services\Fees\FeeAccess;
use App\Support\Institution;
use App\Support\Levels;
use App\Support\Xlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * The fee access rules and the eligibility list: each student's share of the session's fees paid, and whether they may
 * register, write examinations, see their results and use the portal. Staff can allow or bar one student per area.
 */
class FeeAccessController extends Controller
{
    public function __construct(private FeeAccess $access, private ScopedAccess $scoped)
    {
    }

    private function query(Request $request)
    {
        $scope = $this->scoped->scope($request->user(), 'View fee access');
        abort_if($scope->isEmpty(), 403);

        return $scope->students(Student::with('programme.department', 'user'))->whereIn('status', StudentStatus::codesWhere('is_enrolled'))
            ->when($request->filled('programme_id'), fn ($q) => $q->where('programme_id', $request->query('programme_id')))
            ->when($request->filled('department_id'), fn ($q) => $q->whereHas('programme', fn ($p) => $p->where('department_id', $request->query('department_id'))))
            ->when($request->filled('level'), fn ($q) => $q->where('current_level', $request->query('level')))
            ->when(trim((string) $request->query('q')) !== '', fn ($q) => $q->where(fn ($w) => $w->where('matric_number', 'like', '%' . $request->query('q') . '%')->orWhere('last_name', 'like', '%' . $request->query('q') . '%')->orWhere('first_name', 'like', '%' . $request->query('q') . '%')))
            ->orderBy('matric_number')->orderBy('last_name');
    }

    private function session(Request $request): ?AcademicSession
    {
        return $request->filled('session_id') ? AcademicSession::find($request->query('session_id')) : AcademicSession::current();
    }

    /** Keep only students who are allowed / not allowed in the chosen area. */
    private function filterRows($rows, Request $request)
    {
        $area = $request->query('area');
        $state = $request->query('state');
        if (! $area || ! isset(FeeAccess::AREAS[$area]) || ! in_array($state, ['allowed', 'blocked'], true)) {
            return $rows;
        }

        return $rows->filter(fn ($r) => $r['standing']->allows($area) === ($state === 'allowed'))->values();
    }

    public function index(Request $request): View
    {
        $session = $this->session($request);
        $students = $this->query($request)->get();
        $rows = $this->filterRows($this->access->list($students, $session), $request);
        $page = max(1, (int) $request->query('page', 1));
        $paged = new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $summary = collect(FeeAccess::AREAS)->map(fn ($a, $area) => ['label' => $a[0], 'on' => FeeAccess::areaOn($area), 'percent' => FeeAccess::percentFor($area),
            'blocked' => $rows->reject(fn ($r) => $r['standing']->allows($area))->count()]);

        return view('fees.access', [
            'pagetitle' => 'Fee access & eligibility', 'rows' => $paged, 'session' => $session, 'summary' => $summary, 'settings' => $this->access->settings(),
            'sessions' => AcademicSession::orderByDesc('name')->pluck('name', 'id'), 'programmes' => Programme::orderBy('name')->pluck('name', 'id'),
            'departments' => Department::orderBy('name')->pluck('name', 'id'), 'levels' => Levels::options(), 'total' => $rows->count(),
            'canSet' => $request->user()->can('Set fee access rules'), 'canOverride' => $request->user()->can('Manage fee access exceptions'),
        ]);
    }

    public function excel(Request $request): Response
    {
        $session = $this->session($request);
        $rows = $this->filterRows($this->access->list($this->query($request)->get(), $session), $request);
        $head = ['S/N', 'Matric number', 'Name', 'Programme', 'Level', 'Fees billed', 'Paid', 'Of which school loan', '% paid', 'Arrears', 'Loan overdue'];
        foreach (FeeAccess::AREAS as [$label]) {
            $head[] = $label;
        }
        $table = [$head];
        foreach ($rows->values() as $i => $r) {
            $s = $r['standing'];
            $line = [$i + 1, $r['student']->matric_number, $r['student']->full_name, $r['student']->programme?->name, $r['student']->current_level, $s->total, $s->paid, $s->loan, $s->percent, $s->arrears, $s->loanOverdue];
            foreach (array_keys(FeeAccess::AREAS) as $area) {
                $line[] = $s->allows($area) ? 'Yes' : 'No: ' . $s->reason($area);
            }
            $table[] = $line;
        }

        return response(Xlsx::write($table, 'Eligibility', ['widths' => [6, 18, 30, 30, 7, 13, 13, 13, 8, 12, 12, 26, 26, 26, 26], 'freeze' => 3,
            'letterhead' => Institution::letterhead(['Fee eligibility list', ($session?->name ?? '') . ' · printed ' . now()->format('j M Y')]), 'color' => Institution::get('primary_color')]), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="fee-eligibility-' . \Illuminate\Support\Str::slug($session?->name ?? 'session') . '.xlsx"',
        ]);
    }

    public function print(Request $request): View
    {
        $session = $this->session($request);
        $rows = $this->filterRows($this->access->list($this->query($request)->get(), $session), $request);

        return view('fees.access-print', ['rows' => $rows, 'session' => $session, 'area' => $request->query('area'), 'state' => $request->query('state')]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('Set fee access rules'), 403);
        $rules = ['count_loans' => ['nullable', 'boolean'], 'loan_overdue_blocks' => ['nullable', 'boolean'], 'include_arrears' => ['nullable', 'boolean'], 'show_owing' => ['nullable', 'boolean']];
        foreach (array_keys(FeeAccess::AREAS) as $a) {
            $rules["{$a}_on"] = ['nullable', 'boolean'];
            $rules["{$a}_percent"] = ['required', 'integer', 'between:0,100'];
        }
        $this->access->save($request->validate($rules), $request->user());

        return back()->with('success', 'Fee access rules saved. They apply at once.');
    }

    public function setException(Request $request, Student $student): RedirectResponse
    {
        abort_unless($request->user()->can('Manage fee access exceptions'), 403);
        $d = $request->validate([
            'area' => ['required', Rule::in(array_keys(FeeAccess::AREAS))], 'session_id' => ['required', 'exists:academic_sessions,id'],
            'decision' => ['required', Rule::in(['allow', 'bar', 'clear'])], 'reason' => ['nullable', 'string', 'max:500'], 'expires_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        try {
            $this->access->override($student, AcademicSession::findOrFail($d['session_id']), $d['area'], match ($d['decision']) { 'allow' => true, 'bar' => false, default => null },
                $d['reason'] ?? null, $request->user(), $d['expires_on'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $student->full_name . ': ' . FeeAccess::AREAS[$d['area']][0] . ' ' . match ($d['decision']) { 'allow' => 'allowed', 'bar' => 'barred', default => 'back to the fee rule' } . '.');
    }
}
