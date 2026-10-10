<?php

namespace App\Http\Controllers;

use App\Models\MatricBatch;
use App\Services\MatricApproval;
use App\Services\MatricNumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Batches of reserved matric numbers waiting for approval, and the approve-and-publish / reject decision. */
class MatricApprovalController extends Controller
{
    public function __construct(private MatricApproval $approval)
    {
        $this->middleware('permission:View matric numbers')->only('index', 'show');
        $this->middleware('permission:Approve matric numbers')->only('approve', 'reject');
    }

    public function index(): View
    {
        return view('students.matric-approvals', [
            'pagetitle' => 'Matric number approval',
            'pending' => MatricBatch::with('creator')->withCount('students')->where('status', 'pending')->latest('id')->get(),
            'decided' => MatricBatch::with('creator', 'decider')->withCount('proposals')->where('status', '!=', 'pending')->latest('decided_at')->limit(20)->get(),
            'on' => MatricNumberGenerator::requiresApproval(),
        ]);
    }

    public function show(Request $request, MatricBatch $batch): View
    {
        $rows = $batch->status === 'pending' ? $this->approval->check($batch) : collect();

        return view('students.matric-approval', [
            'pagetitle' => 'Matric batch ' . $batch->code, 'batch' => $batch->load('creator', 'decider'), 'rows' => $rows,
            'problems' => $rows->whereNotNull('problem')->count(),
            'history' => $batch->status === 'pending' ? collect() : $batch->proposals()->with('student')->orderBy('matric_number')->get(),
            'ownBatch' => MatricNumberGenerator::separateApprover() && $batch->created_by === $request->user()->id,
        ]);
    }

    public function approve(Request $request, MatricBatch $batch): RedirectResponse
    {
        $d = $request->validate(['note' => ['nullable', 'string', 'max:500'], 'confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm the list has been checked.']);
        try {
            $r = $this->approval->approve($batch, $request->user(), $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('matric.approvals.show', $batch)->with('success', "{$r['published']} matric number(s) approved and published (operation {$r['operation']}). The students have been told."
            . ($r['released'] ? ' Not published: ' . implode(' | ', $r['released']) : ''));
    }

    public function reject(Request $request, MatricBatch $batch): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        try {
            $n = $this->approval->reject($batch, $request->user(), $d['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('matric.approvals.index')->with('success', "Batch rejected. {$n} number(s) were released and those students are waiting for a number again.");
    }
}
