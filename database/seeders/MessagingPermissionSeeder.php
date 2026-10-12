<?php

namespace Database\Seeders;

/** Permissions for: SMS and WhatsApp messaging. */
class MessagingPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Messaging',
        ];
    }
}
