<?php

namespace Database\Seeders;

/** Permissions for: Applicants, students, clearance and matric numbers. */
class AdmissionPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Applicants',
            'Application settings',
            'Application types',
            'Students',
            'Clearance',
            'Clearance items',
            'Matric numbers',
            'Matric number format',
            'O Level results',
        ];
    }
}
