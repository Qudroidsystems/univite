<?php

namespace Database\Seeders;

/** Permissions for: Result entry, approval stages, transcripts, complaints and graduation lists. */
class ResultPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Result stages',
            'Result sheets',
            'Legacy results',
            'Student results',
            'Result settings',
            'Result complaints',
            'Graduation lists',
        ];
    }
}
