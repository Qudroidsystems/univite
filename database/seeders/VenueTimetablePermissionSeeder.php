<?php

namespace Database\Seeders;

/** Permissions for: Venues, timetables and examination seating. */
class VenueTimetablePermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Venues',
            'Timetables',
            'Examination seating',
        ];
    }
}
