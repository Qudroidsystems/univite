<?php

namespace Database\Seeders;

/** Permissions for: Library. */
class LibraryPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Library',
        ];
    }
}
