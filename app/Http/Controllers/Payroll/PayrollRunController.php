<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Payroll\PayrollSchedules;
use App\Services\Payroll\PayrollService;
use App\Support\Lookups;
use App\Support\Reversals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** The monthly payroll: prepare, check, approve, mark paid; payslips; bank, pension, tax and deduction schedules. */
class PayrollRunController extends Controller
{
    public function __construct(private PayrollService $payroll, private PayrollSchedules $schedules)
    {
        $this->middleware('permission:View payroll|Prepare payroll|Approve payroll')->only('index', 'show', 'payslip', 'schedule');
        $this->middleware('permission:Prepare payroll')->only('store', 'destroy');
        $this->middleware('permission:Approve payroll')->only('approve');
        $this->middleware('permission:Mark payroll paid')->only('paid');
    }

    public function index(): View
    {
        $latest = PayrollRun::max('period');

        return view('payroll.runs', [
            'pagetitle' => 'Payroll', 'runs' => PayrollRun::with('approver')->orderByDesc('period')->paginate(24),
            'next' => $latest ? \Carbon\Carbon::createFromFormat('!Y-m', $latest)->addMonth()->format('Y-m') : now()->format('Y-m'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        try {
            $run = $this->payroll->prepare($d['period'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.runs.show', $run)->with('success', "Payroll for {$run->label()} prepared: {$run->staff_count} payslip(s). Check it, then approve.");
    }

    public function show(Request $request, PayrollRun $run): View
    {
        $q = trim((string) $request->query('q'));
        $slips = $run->payslips()->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('staff_number', 'like', "%{$q}%")->orWhere('department', 'like', "%{$q}%")))
            ->when($request->query('flagged'), fn ($w) => $w->whereNotNull('notes'))->orderBy('department')->orderBy('name')->paginate(50)->withQueryString();

        return view('payroll.run', [
            'pagetitle' => 'Payroll · ' . $run->label(), 'run' => $run->load('creator', 'approver'), 'slips' => $slips,
            'flagged' => $run->payslips()->whereNotNull('notes')->count(), 'changed' => $run->isDraft() ? $this->payroll->changedSince($run) : null,
            'origin' => Reversals::originOf('payroll_approve', 'PayrollRun', $run->id), 'deductions' => $this->schedules->deductionChoices($run),
            'pfas' => $run->payslips()->where('pension_employee', '>', 0)->distinct()->pluck('pfa_code')->mapWithKeys(fn ($c) => [(string) $c => $c ? Lookups::label('pfa', $c) : 'No PFA on record']),
        ]);
    }

    public function approve(Request $request, PayrollRun $run): RedirectResponse
    {
        try {
            $this->payroll->approve($run, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Payroll for {$run->label()} approved. Staff can now see their payslips.");
    }

    public function paid(Request $request, PayrollRun $run): RedirectResponse
    {
        try {
            $this->payroll->markPaid($run, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Payroll for {$run->label()} marked paid.");
    }

    public function destroy(PayrollRun $run): RedirectResponse
    {
        try {
            $this->payroll->discard($run);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.runs.index')->with('success', 'Draft payroll deleted.');
    }

    public function payslip(Payslip $payslip): View
    {
        return view('payroll.payslip', ['pagetitle' => 'Payslip · ' . $payslip->name, 'slip' => $payslip->load('run', 'lines'), 'mine' => false]);
    }

    /** A schedule on screen, or as a CSV file with ?download=1. */
    public function schedule(Request $request, PayrollRun $run, string $kind): View|Response
    {
        $schedule = match ($kind) {
            'bank' => $this->schedules->bank($run),
            'pension' => $this->schedules->pension($run, $request->has('pfa') ? (string) $request->query('pfa') : null),
            'tax' => $this->schedules->tax($run),
            'deduction' => $this->schedules->deduction($run, (string) $request->query('item', '')),
            default => abort(404),
        };
        if ($request->boolean('download')) {
            $name = \Illuminate\Support\Str::slug($schedule->title) . '.csv';

            return response($schedule->csv(), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
        }

        return view('payroll.schedule', ['pagetitle' => $schedule->title, 'run' => $run, 'schedule' => $schedule, 'kind' => $kind]);
    }
}
