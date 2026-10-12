<?php

namespace Database\Seeders;

/** Permissions for: Identity cards. */
class IdCardPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Identity cards',
        ];
    }
}
