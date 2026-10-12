<?php

namespace App\Services\Payroll;

use App\Data\Payroll\TaxTable;
use App\Models\Setting;

/** Payroll settings: the income tax table, which employment statuses are paid, and the pension element codes. */
class PayrollSettings
{
    /**
     * Personal income tax bands of the Nigeria Tax Act 2025 (in force from January 2026), as yearly amounts.
     * They are only a starting point: the bursary checks them and changes them when the law changes.
     */
    public const DEFAULT_TAX_BANDS = [
        ['upto' => 800000, 'rate' => 0], ['upto' => 3000000, 'rate' => 15], ['upto' => 12000000, 'rate' => 18],
        ['upto' => 25000000, 'rate' => 21], ['upto' => 50000000, 'rate' => 23], ['upto' => null, 'rate' => 25],
    ];

    public const DEFAULT_PAID_STATUSES = ['active', 'study_leave', 'secondment'];

    /** Elements whose amounts go on the pension schedule. */
    public const PENSION_EMPLOYEE = 'PEN_EE';
    public const PENSION_EMPLOYER = 'PEN_ER';

    public function taxTable(): TaxTable
    {
        $rows = json_decode((string) Setting::get('payroll.tax_bands'), true);

        return TaxTable::fromArray(is_array($rows) && $rows ? $rows : self::DEFAULT_TAX_BANDS);
    }

    public function saveTaxTable(TaxTable $table): void
    {
        Setting::put('payroll.tax_bands', json_encode($table->toArray()));
    }

    /** @return string[] */
    public function paidStatuses(): array
    {
        $v = Setting::get('payroll.paid_statuses');

        return $v === null ? self::DEFAULT_PAID_STATUSES : array_values(array_filter(explode(',', $v)));
    }

    /** @param  string[]  $codes */
    public function savePaidStatuses(array $codes): void
    {
        Setting::put('payroll.paid_statuses', implode(',', $codes));
    }

    /** Email each member of staff their payslip when a payroll is approved. */
    public function emailPayslips(): bool
    {
        return Setting::get('payroll.email_payslips', '1') === '1';
    }

    public function setEmailPayslips(bool $on): void
    {
        Setting::put('payroll.email_payslips', $on ? '1' : '0');
    }
}
