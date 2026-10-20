<?php

namespace App\Http\Controllers\Fees;

use App\Http\Controllers\Controller;
use App\Models\StudentLoan;
use App\Services\Fees\LoanService;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** The bursary's side of the school loan scheme. */
class LoanAdminController extends Controller
{
    public function __construct(private LoanService $loans)
    {
        $this->middleware('permission:View school loans|Approve school loans|Record loan repayments|Set loan policy|Manage school loans');
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', 'pending');
        $rows = StudentLoan::with('student.programme', 'session', 'invoice')
            ->when($status === 'overdue', fn ($q) => $q->where('status', 'disbursed'))
            ->when(! in_array($status, ['all', 'overdue'], true), fn ($q) => $q->where('status', $status))
            ->when(trim((string) $request->query('q')) !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', '%' . $request->query('q') . '%')
                ->orWhereHas('student', fn ($s) => $s->where('matric_number', 'like', '%' . $request->query('q') . '%')->orWhere('last_name', 'like', '%' . $request->query('q') . '%'))))
            ->latest('id')->get();
        if ($status === 'overdue') {
            $rows = $rows->filter(fn ($l) => $l->overdue() > 0)->values();
        }
        $live = StudentLoan::where('status', 'disbursed')->get();

        return view('loans.index', [
            'pagetitle' => 'School loans', 'rows' => $rows, 'status' => $status,
            'counts' => StudentLoan::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'totals' => ['out' => round($live->sum(fn ($l) => $l->balance), 2), 'overdue' => round($live->sum(fn ($l) => $l->overdue()), 2), 'overdueCount' => $live->filter(fn ($l) => $l->overdue() > 0)->count(),
                'lent' => round((float) StudentLoan::whereIn('status', ['disbursed', 'settled', 'written_off'])->sum('amount_approved'), 2), 'repaid' => round((float) StudentLoan::sum('amount_repaid'), 2)],
            'settings' => collect(LoanService::DEFAULTS)->mapWithKeys(fn ($v, $k) => [substr($k, 6) => Setting::get($k, $v)])->all(),
        ]);
    }

    public function show(StudentLoan $loan): View
    {
        return view('loans.show', ['pagetitle' => 'Loan ' . $loan->reference, 'loan' => $loan->load('student.programme', 'student.user', 'session', 'invoice', 'repayments.recorder', 'decider'),
            'channels' => LoanService::REPAY_CHANNELS, 'maxInstalments' => (int) LoanService::get('max_instalments'), 'interest' => (float) LoanService::get('interest_percent'),
            'firstDue' => today()->addDays((int) LoanService::get('first_due_days'))->toDateString()]);
    }

    private function run(callable $fn, string $ok): RedirectResponse
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }

    public function approve(Request $request, StudentLoan $loan): RedirectResponse
    {
        abort_unless($request->user()->can('Approve school loans'), 403);
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'instalments' => ['required', 'integer', 'min:1'], 'first_due' => ['nullable', 'date'],
            'interest' => ['nullable', 'numeric', 'between:0,100'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->run(fn () => $this->loans->approve($loan, $request->user(), (float) $d['amount'], (int) $d['instalments'], $d['first_due'] ?? null, isset($d['interest']) ? (float) $d['interest'] : null, $d['note'] ?? null), 'Loan approved. Pay it against the student\'s fees when ready.');
    }

    public function decline(Request $request, StudentLoan $loan): RedirectResponse
    {
        abort_unless($request->user()->can('Approve school loans'), 403);
        $d = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return $this->run(fn () => $this->loans->decline($loan, $request->user(), $d['note']), 'Loan declined. The student has been told.');
    }

    public function disburse(Request $request, StudentLoan $loan): RedirectResponse
    {
        abort_unless($request->user()->can('Approve school loans'), 403);

        return $this->run(fn () => $this->loans->disburse($loan, $request->user()), 'Loan paid against the fees invoice. It now counts as fees paid.');
    }

    public function repay(Request $request, StudentLoan $loan): RedirectResponse
    {
        abort_unless($request->user()->can('Record loan repayments'), 403);
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'channel' => ['required', Rule::in(array_keys(LoanService::REPAY_CHANNELS))],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'], 'reference' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->run(fn () => $this->loans->repay($loan, $request->user(), (float) $d['amount'], $d['channel'], $d['paid_on'] ?? null, $d['reference'] ?? null, $d['note'] ?? null), 'Repayment recorded.');
    }

    public function close(Request $request, StudentLoan $loan): RedirectResponse
    {
        abort_unless($request->user()->can('Manage school loans'), 403);
        $d = $request->validate(['action' => ['required', Rule::in(['write_off', 'cancel'])], 'note' => ['required', 'string', 'max:1000']]);

        return $this->run(fn () => $d['action'] === 'write_off' ? $this->loans->writeOff($loan, $request->user(), $d['note']) : $this->loans->cancel($loan, $request->user(), $d['note']),
            $d['action'] === 'write_off' ? 'Loan written off.' : 'Loan cancelled.');
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('Set loan policy'), 403);
        $d = $request->validate(['enabled' => ['nullable', 'boolean'], 'require_guarantor' => ['nullable', 'boolean'], 'max_percent' => ['required', 'numeric', 'between:1,100'],
            'interest_percent' => ['required', 'numeric', 'between:0,100'], 'max_instalments' => ['required', 'integer', 'between:1,24'], 'interval_days' => ['required', 'integer', 'between:7,365'],
            'first_due_days' => ['required', 'integer', 'between:0,365'], 'terms' => ['nullable', 'string', 'max:2000']]);
        $this->loans->saveSettings($d, $request->user());

        return back()->with('success', 'Loan scheme settings saved.');
    }
}
