<?php

namespace Database\Seeders;

/** Permissions for: Institution profile, sessions, operation history and emailed documents. */
class InstitutionPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Institution profile',
            'Academic sessions',
            'Session lifecycle',
            'Operation history',
            'Emailed documents',
        ];
    }
}
