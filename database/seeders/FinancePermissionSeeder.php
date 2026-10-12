<?php

namespace Database\Seeders;

/** Permissions for: Bill items, school bills, invoices and payments. */
class FinancePermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Bill items',
            'School bills',
            'Invoices & payments',
        ];
    }
}
