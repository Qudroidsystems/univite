<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Programme;
use App\Models\Setting;
use App\Services\MatricNumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MatricSettingsController extends Controller
{
    public function __construct(private MatricNumberGenerator $generator, private \App\Services\OperationAudit $audit)
    {
        $this->middleware('permission:View matric number format')->only('edit');
        $this->middleware('permission:Set the matric number format')->only(['update', 'updateIssue', 'updateApproval', 'updateSequence']);
    }

    public function edit(Request $request): View
    {
        $pattern = trim((string) $request->query('pattern', MatricNumberGenerator::pattern()));
        $error = MatricNumberGenerator::validate($pattern);

        $programme = Programme::with('department.faculty')->where('is_active', true)->orderBy('id')->first();
        $session = AcademicSession::current() ?? AcademicSession::orderByDesc('name')->first();
        $preview = ($programme && $session && ! $error) ? $this->generator->preview($programme, $session, $pattern) : null;

        return view('students.matric-settings', [
            'pagetitle' => 'Matric Number Format',
            'pattern'   => $pattern,
            'saved'     => MatricNumberGenerator::pattern(),
            'error'     => $error,
            'preview'   => $preview,
            'tokens'    => MatricNumberGenerator::TOKENS,
            'issueAt'   => MatricNumberGenerator::issueAt(),
            'autoIssue' => MatricNumberGenerator::autoIssue(),
            'approval'  => MatricNumberGenerator::requiresApproval(),
            'separate'  => MatricNumberGenerator::separateApprover(),
            'seqSession' => $seqSession = ($request->filled('seq_session') ? AcademicSession::find($request->query('seq_session')) : null) ?? $session,
            'sequences' => $seqSession && ! MatricNumberGenerator::validate(MatricNumberGenerator::pattern()) ? $this->generator->sequences($seqSession) : [],
            'sessions'  => AcademicSession::orderByDesc('name')->pluck('name', 'id'),
            'sample'    => $programme ? $programme->name . ' (' . $programme->code . '), ' . ($session?->name ?? 'no session yet') : null,
        ]);
    }

    public function updateIssue(Request $request): RedirectResponse
    {
        $data = $request->validate(['issue_at' => ['required', 'in:clearance,admission'], 'auto_issue' => ['nullable', 'boolean']]);

        Setting::put('matric.issue_at', $data['issue_at']);
        Setting::put('matric.auto_issue', $request->boolean('auto_issue') ? '1' : '0');

        return redirect()->route('students.settings')->with('success', 'Saved. This applies to students admitted or cleared from now on.');
    }

    public function updateApproval(Request $request): RedirectResponse
    {
        Setting::put('matric.require_approval', $request->boolean('require_approval') ? '1' : '0');
        Setting::put('matric.separate_approver', $request->boolean('separate_approver') ? '1' : '0');

        return redirect()->route('students.settings')->with('success', $request->boolean('require_approval')
            ? 'Saved. New matric numbers are reserved and only published when their batch is approved.'
            : 'Saved. New matric numbers are published as soon as they are generated.');
    }

    /** Set the last number already used for a prefix, so the next one carries on from it. */
    public function updateSequence(Request $request): RedirectResponse
    {
        $d = $request->validate(['prefix' => ['required', 'string', 'max:80'], 'last' => ['required', 'integer', 'min:0', 'max:99999999'], 'seq_session' => ['nullable', 'integer']]);
        try {
            $this->generator->setLastUsed($d['prefix'], (int) $d['last']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $op = $this->audit->note('matric_sequence', 'matric', $request->user(), 'sequence_set', null, null, ['prefix' => $d['prefix'], 'last' => (int) $d['last']]);

        return redirect()->route('students.settings', array_filter(['seq_session' => $d['seq_session'] ?? null]))->with('success', "Saved. The next number for that prefix continues after {$d['last']}.");
    }

    public function update(Request $request): RedirectResponse
    {
        $pattern = trim((string) $request->input('pattern'));

        if ($error = MatricNumberGenerator::validate($pattern)) {
            return back()->withInput()->withErrors(['pattern' => $error]);
        }

        Setting::put(MatricNumberGenerator::SETTING_KEY, $pattern);

        return redirect()->route('students.settings')->with('success', 'Matric number format saved. It applies to numbers issued from now on; existing matric numbers do not change.');
    }
}
