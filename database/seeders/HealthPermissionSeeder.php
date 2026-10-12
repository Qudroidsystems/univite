<?php

namespace Database\Seeders;

/** Permissions for: Health centre and clinic records. */
class HealthPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Health & clinic',
        ];
    }
}
