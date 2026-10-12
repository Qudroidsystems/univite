<?php

namespace Database\Seeders;

/** Permissions for: Polls and elections. */
class PollPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Polls & elections',
        ];
    }
}
