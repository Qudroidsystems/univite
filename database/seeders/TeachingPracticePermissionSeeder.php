<?php

namespace Database\Seeders;

/** Permissions for: Teaching practice. */
class TeachingPracticePermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Teaching practice',
        ];
    }
}
