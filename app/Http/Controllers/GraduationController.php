<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\GraduationEntry;
use App\Models\GraduationList;
use App\Models\Programme;
use App\Services\GraduationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Graduation lists: run the final-year check per programme, hold students back, approve. */
class GraduationController extends Controller
{
    public function __construct(private GraduationService $graduation)
    {
        $this->middleware('permission:View graduation lists')->only('index', 'show');
        $this->middleware('permission:Generate graduation lists')->only('run', 'exclude');
        $this->middleware('permission:Approve graduation lists')->only('approve');
    }

    public function index(): View
    {
        return view('graduation.index', [
            'pagetitle' => 'Graduation lists',
            'lists' => GraduationList::with('programme', 'session')->withCount([
                'entries', 'entries as eligible_count' => fn ($q) => $q->where('is_eligible', true)->where('is_excluded', false),
            ])->latest('updated_at')->paginate(25),
            'programmes' => Programme::orderBy('name')->pluck('name', 'id'),
            'sessions' => AcademicSession::orderByDesc('name')->pluck('name', 'id'),
            'current' => AcademicSession::current()?->id,
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $d = $request->validate(['programme_id' => ['required', 'exists:programmes,id'], 'academic_session_id' => ['required', 'exists:academic_sessions,id']]);

        try {
            $list = $this->graduation->run(Programme::findOrFail($d['programme_id']), (int) $d['academic_session_id'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('graduation.show', $list->id)->with('success', 'Checked ' . $list->entries()->count() . ' final-year student(s).');
    }

    public function show(GraduationList $list): View
    {
        $list->load('programme.department.faculty', 'programme.studyCategory', 'session', 'approver', 'entries.student');

        return view('graduation.show', [
            'pagetitle' => 'Graduation list', 'list' => $list,
            'entries' => $list->entries->sortBy([fn ($a, $b) => $b->graduates() <=> $a->graduates(), fn ($a, $b) => $b->cgpa <=> $a->cgpa]),
            'finalLevel' => $this->graduation->levelLabel($list->programme),
        ]);
    }

    public function exclude(Request $request, GraduationEntry $entry): RedirectResponse
    {
        $d = $request->validate(['exclude' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);

        try {
            $this->graduation->exclude($entry, (bool) $d['exclude'], $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $d['exclude'] ? 'Student held back from this list.' : 'Student put back on the list.');
    }

    public function approve(Request $request, GraduationList $list): RedirectResponse
    {
        try {
            $n = $this->graduation->approve($list, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$n} student(s) graduated.");
    }
}
