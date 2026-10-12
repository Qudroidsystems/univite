<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The statutory payroll items every Nigerian employer starts with. Allowances differ from one university to the next,
 * so none are added: the bursary adds its own. Amounts and rates here can all be changed; the items cannot be deleted.
 */
class PayrollDefaults
{
    /** code => [name, kind, method, rate, tax relief, description] */
    public static function elements(): array
    {
        return [
            'PEN_EE' => ['Pension (employee)', 'deduction', 'percent_pensionable', 8, true, 'Employee\'s contribution to their Retirement Savings Account under the Pension Reform Act: at least 8% of basic, housing and transport. Mark the allowances that count as pensionable.'],
            'PEN_ER' => ['Pension (employer)', 'employer', 'percent_pensionable', 10, false, 'The university\'s contribution to the Retirement Savings Account: at least 10% of pensionable pay. Shown on the payslip and the pension schedule; not deducted from pay.'],
            'NHF' => ['National Housing Fund', 'deduction', 'percent_basic', 2.5, true, 'National Housing Fund contribution: 2.5% of basic salary.'],
            'PAYE' => ['Income tax (PAYE)', 'deduction', 'paye', null, false, 'Worked out from the tax table in Payroll Settings, on taxable pay less tax-free deductions (pension, NHF).'],
        ];
    }

    public static function seed(): void
    {
        $now = now();
        $i = 0;
        foreach (self::elements() as $code => [$name, $kind, $method, $rate, $relief, $description]) {
            DB::table('pay_elements')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'kind' => $kind, 'method' => $method, 'rate' => $rate, 'taxable' => false, 'pensionable' => false,
                'tax_relief' => $relief, 'sort_order' => 900 + (++$i), 'is_active' => true, 'is_system' => true, 'description' => $description,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
