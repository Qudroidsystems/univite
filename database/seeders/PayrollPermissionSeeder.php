<?php

namespace Database\Seeders;

/** Permissions for: Payroll, payslips and pensions. */
class PayrollPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Payroll',
        ];
    }
}
