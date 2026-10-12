<?php

namespace App\Services\Payroll;

use App\Data\Payroll\Schedule;
use App\Models\PayrollRun;
use App\Models\PayslipLine;
use App\Support\Lookups;

/** The lists a payroll produces for the bank, the pension fund administrators, the tax office and other deductions. */
class PayrollSchedules
{
    public const KINDS = ['bank' => 'Bank schedule', 'pension' => 'Pension schedule (by PFA)', 'tax' => 'PAYE schedule (by state)', 'deduction' => 'Deduction schedule'];

    public function bank(PayrollRun $run): Schedule
    {
        $rows = $run->payslips()->orderBy('bank_name')->orderBy('name')->get()->map(fn ($s) => [
            $s->name, $s->staff_number, $s->bank_name, $s->bank_code, $s->bank_account_number, $s->bank_account_name, round($s->net, 2),
        ])->all();

        return new Schedule("Bank schedule · {$run->label()}", ['Name', 'Staff number', 'Bank', 'Bank code', 'Account number', 'Account name', 'Net pay'], $rows, [6]);
    }

    /** Employee and employer contributions per member of staff, PFA by PFA. Pass a PFA code for one PFA only. */
    public function pension(PayrollRun $run, ?string $pfa = null): Schedule
    {
        $rows = $run->payslips()->where(fn ($q) => $q->where('pension_employee', '>', 0)->orWhere('pension_employer', '>', 0))
            ->when($pfa !== null, fn ($q) => $pfa === '' ? $q->whereNull('pfa_code') : $q->where('pfa_code', $pfa))
            ->orderBy('pfa_code')->orderBy('name')->get()
            ->map(fn ($s) => [$s->pfa_code ? Lookups::label('pfa', $s->pfa_code) : 'No PFA on record', $s->name, $s->staff_number, $s->rsa_pin,
                round($s->pension_employee, 2), round($s->pension_employer, 2), round($s->pension_employee + $s->pension_employer, 2)])->all();

        return new Schedule("Pension schedule · {$run->label()}", ['PFA', 'Name', 'Staff number', 'RSA PIN', 'Employee', 'Employer', 'Total'], $rows, [4, 5, 6], 'PFA');
    }

    public function tax(PayrollRun $run): Schedule
    {
        $rows = $run->payslips()->where('tax', '>', 0)->orderBy('tax_state')->orderBy('name')->get()
            ->map(fn ($s) => [$s->tax_state ?: 'Not set', $s->name, $s->staff_number, $s->tax_id, round($s->gross, 2), round($s->taxable, 2), round($s->tax, 2)])->all();

        return new Schedule("PAYE schedule · {$run->label()}", ['State', 'Name', 'Staff number', 'Tax ID', 'Gross pay', 'Taxable pay', 'Tax'], $rows, [4, 5, 6], 'State');
    }

    /** Everyone with a given deduction (a pay element code, or the name of a personal deduction). */
    public function deduction(PayrollRun $run, string $which): Schedule
    {
        $lines = PayslipLine::query()->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->where('payslips.payroll_run_id', $run->id)->where('payslip_lines.kind', 'deduction')
            ->where(fn ($q) => $q->where('payslip_lines.code', $which)->orWhere(fn ($x) => $x->whereNull('payslip_lines.code')->where('payslip_lines.name', 'like', $which . '%')))
            ->orderBy('payslips.name')->get(['payslips.name as staff', 'payslips.staff_number', 'payslip_lines.name', 'payslip_lines.amount']);
        $title = $lines->first()?->name ?? $which;

        return new Schedule("{$title} · {$run->label()}", ['Name', 'Staff number', 'Item', 'Amount'], $lines->map(fn ($l) => [$l->staff, $l->staff_number, $l->name, round((float) $l->amount, 2)])->all(), [3]);
    }

    /** Deductions on this payroll, for choosing a deduction schedule: [code or name => label]. */
    public function deductionChoices(PayrollRun $run): array
    {
        return PayslipLine::query()->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->where('payslips.payroll_run_id', $run->id)->where('payslip_lines.kind', 'deduction')
            ->get(['payslip_lines.code', 'payslip_lines.name'])
            ->mapWithKeys(fn ($l) => $l->code ? [$l->code => $l->name] : [preg_replace('/ \(balance after.*$/', '', $l->name) => preg_replace('/ \(balance after.*$/', '', $l->name)])
            ->sort()->all();
    }
}
