<?php

namespace Database\Seeders;

/** Permissions for: Staff leave. */
class LeavePermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Staff leave',
        ];
    }
}
