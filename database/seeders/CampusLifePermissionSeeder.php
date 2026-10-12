<?php

namespace Database\Seeders;

/** Permissions for: the mobile app's campus life (settings, feed, marketplace, reports, course materials). */
class CampusLifePermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Campus life',
        ];
    }
}
