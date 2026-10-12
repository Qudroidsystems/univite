<?php

namespace App\Http\Controllers\Payroll;

use App\Data\Payroll\TaxTable;
use App\Http\Controllers\Controller;
use App\Services\Hr\RetirementRules;
use App\Services\OperationAudit;
use App\Services\Payroll\PayrollSettings;
use App\Support\Lookups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The income tax table, which statuses are paid, emailing payslips, and the retirement ages. */
class PayrollSettingsController extends Controller
{
    public function __construct(private PayrollSettings $settings, private RetirementRules $retirement, private OperationAudit $audit)
    {
        $this->middleware('permission:Set payroll policy|View payroll')->only('edit');
        $this->middleware('permission:Set payroll policy')->only('update');
    }

    public function edit(): View
    {
        return view('payroll.settings', [
            'pagetitle' => 'Payroll settings', 'bands' => $this->settings->taxTable()->bands, 'paid' => $this->settings->paidStatuses(),
            'statuses' => Lookups::options('employment_status'), 'email' => $this->settings->emailPayslips(),
            'retirement' => collect(RetirementRules::DEFAULTS)->map(fn ($v, $k) => $this->retirement->setting($k)),
            'defaults' => PayrollSettings::DEFAULT_TAX_BANDS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'bands' => ['required', 'array', 'min:1', 'max:12'], 'bands.*.upto' => ['nullable', 'numeric', 'min:0'], 'bands.*.rate' => ['nullable', 'numeric', 'between:0,100'],
            'paid' => ['nullable', 'array'], 'paid.*' => ['string', 'max:30'], 'email_payslips' => ['nullable', 'boolean'],
            'retirement_age' => ['required', 'integer', 'between:50,80'], 'retirement_age_professorial' => ['required', 'integer', 'between:50,80'],
            'max_service_years' => ['required', 'integer', 'between:0,50'],
        ]);
        $rows = array_values(array_filter($d['bands'], fn ($b) => ($b['rate'] ?? '') !== '' && $b['rate'] !== null));
        $open = array_filter($rows, fn ($b) => ($b['upto'] ?? null) === null || $b['upto'] === '');
        if (count($open) !== 1) {
            return back()->withInput()->with('error', 'Leave "up to" blank on exactly one band: the top band, which has no upper limit.');
        }
        $before = ['bands' => $this->settings->taxTable()->toArray(), 'paid' => $this->settings->paidStatuses()];
        $table = TaxTable::fromArray($rows);
        $this->settings->saveTaxTable($table);
        $this->settings->savePaidStatuses($d['paid'] ?? []);
        $this->settings->setEmailPayslips($request->boolean('email_payslips'));
        $this->retirement->save($d);
        $this->audit->note('payroll_policy', 'payroll', $request->user(), 'settings_changed', null, $before, ['bands' => $table->toArray(), 'paid' => $d['paid'] ?? []]);

        return back()->with('success', 'Payroll settings saved. They apply to payrolls prepared from now on.');
    }
}
