<?php

namespace Database\Seeders;

/** Permissions for: Hostels and bed allocation. */
class AccommodationPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Accommodation',
        ];
    }
}
