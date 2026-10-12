<?php

namespace Database\Seeders;

/** Permissions for: Staff records and units. */
class StaffPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Staff records',
            'Units',
        ];
    }
}
