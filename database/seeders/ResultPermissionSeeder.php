<?php

namespace Database\Seeders;

/** Permissions for: Result entry, approval stages, transcripts, complaints and graduation lists. */
class ResultPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Result stages',
            'Assessment schemes',
            'Result sheets',
            'Legacy results',
            'Student results',
            'Result computation',
            'Result settings',
            'Result complaints',
            'Graduation lists',
        ];
    }
}
