<?php

namespace App\Services\Payroll;

use App\Data\Payroll\PayLine;
use App\Data\Payroll\PayslipDraft;
use App\Data\Payroll\TaxTable;
use App\Models\PayElement;
use App\Models\StaffPayItem;
use App\Models\StaffProfile;
use Illuminate\Support\Collection;

/**
 * Works out one payslip. It reads nothing from the database: everything it needs is passed in.
 *
 * Order: basic salary and allowances (all scaled by the share of the month the person is paid for), then the
 * person's own earnings; then deductions that are taken before tax (pension, NHF); then income tax on what is left
 * of taxable pay, worked out on the yearly amount and divided by twelve; then every other deduction. A loan is
 * never deducted beyond what is still owed.
 */
class PayCalculator
{
    /**
     * @param  Collection<int, PayElement>  $elements  active elements, in payslip order
     * @param  Collection<int, StaffPayItem>  $items  this person's own items running this month
     * @param  float  $factor  share of the month paid, 0..1
     */
    public function calculate(StaffProfile $p, float $annualBasic, Collection $elements, Collection $items, TaxTable $tax, float $factor = 1.0): PayslipDraft
    {
        $notes = [];
        $lines = [];
        $basic = round($annualBasic / 12 * $factor, 2);
        $lines[] = new PayLine('earning', 'BASIC', 'Basic salary', $basic);
        $gross = $basic;
        $taxable = $basic;
        $pensionable = $basic;
        $applicable = $elements->filter(fn (PayElement $e) => $e->appliesTo($p));

        foreach ($applicable->where('kind', 'earning') as $e) {
            $amount = round($this->amount($e, $p, $basic, 0) * ($e->method === 'percent_basic' ? 1 : $factor), 2);
            if ($amount <= 0) {
                continue;
            }
            $lines[] = new PayLine('earning', $e->code, $e->name, $amount);
            $gross += $amount;
            $taxable += $e->taxable ? $amount : 0;
            $pensionable += $e->pensionable ? $amount : 0;
        }
        foreach ($items->where('kind', 'earning') as $i) {
            $lines[] = new PayLine('earning', null, $i->name, round($i->amount, 2), $i->id);
            $gross += $i->amount;
            $taxable += $i->taxable ? $i->amount : 0;
        }

        // Deductions taken off income before tax (pension, NHF ...), then tax, then the rest.
        $deductions = 0.0;
        $relief = 0.0;
        $pensionEmployee = 0.0;
        foreach ($applicable->where('kind', 'deduction')->where('tax_relief', true)->where('method', '!=', 'paye') as $e) {
            $amount = round($this->amount($e, $p, $basic, $pensionable), 2);
            if ($amount <= 0) {
                continue;
            }
            $lines[] = new PayLine('deduction', $e->code, $e->name, $amount);
            $deductions += $amount;
            $relief += $amount;
            $pensionEmployee += $e->code === PayrollSettings::PENSION_EMPLOYEE ? $amount : 0;
        }

        $taxableAfterRelief = max(0, $taxable - $relief);
        $incomeTax = 0.0;
        if ($paye = $applicable->first(fn ($e) => $e->kind === 'deduction' && $e->method === 'paye')) {
            $incomeTax = round($tax->annualTax($taxableAfterRelief * 12) / 12, 2);
            if ($incomeTax > 0) {
                $lines[] = new PayLine('deduction', $paye->code, $paye->name, $incomeTax);
                $deductions += $incomeTax;
            }
        }

        foreach ($applicable->where('kind', 'deduction')->where('tax_relief', false)->where('method', '!=', 'paye') as $e) {
            $amount = round($this->amount($e, $p, $basic, $pensionable), 2);
            if ($amount > 0) {
                $lines[] = new PayLine('deduction', $e->code, $e->name, $amount);
                $deductions += $amount;
                $pensionEmployee += $e->code === PayrollSettings::PENSION_EMPLOYEE ? $amount : 0;
            }
        }
        foreach ($items->where('kind', 'deduction') as $i) {
            $amount = round($i->isLoan() ? min($i->amount, (float) $i->balance) : $i->amount, 2);
            if ($amount > 0) {
                $lines[] = new PayLine('deduction', null, $i->name . ($i->isLoan() ? ' (balance after: ₦' . number_format((float) $i->balance - $amount, 2) . ')' : ''), $amount, $i->id);
                $deductions += $amount;
            }
        }

        $pensionEmployer = 0.0;
        foreach ($applicable->where('kind', 'employer') as $e) {
            $amount = round($this->amount($e, $p, $basic, $pensionable), 2);
            if ($amount > 0) {
                $lines[] = new PayLine('employer', $e->code, $e->name, $amount);
                $pensionEmployer += $e->code === PayrollSettings::PENSION_EMPLOYER ? $amount : 0;
            }
        }

        $gross = round($gross, 2);
        $deductions = round($deductions, 2);
        $net = round($gross - $deductions, 2);
        if ($net < 0) {
            $notes[] = 'Deductions are more than pay this month.';
        }
        if ($factor < 1) {
            $notes[] = 'Paid for ' . round($factor * 100, 1) . '% of the month.';
        }

        return new PayslipDraft($lines, round($factor, 4), $basic, $gross, round($taxableAfterRelief, 2), $incomeTax, round($pensionEmployee, 2), round($pensionEmployer, 2), $deductions, $net, $notes);
    }

    /** A month's amount for an element before any share-of-month scaling (basic is already scaled). */
    private function amount(PayElement $e, StaffProfile $p, float $basic, float $pensionable): float
    {
        return match ($e->method) {
            'fixed' => (float) $e->amount,
            'percent_basic' => $basic * (float) $e->rate / 100,
            'percent_pensionable' => $pensionable * (float) $e->rate / 100,
            'grade_table' => (float) (($e->grade_amounts ?? [])[(string) $p->grade_level] ?? 0),
            default => 0.0,
        };
    }
}
