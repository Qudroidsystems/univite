<?php

namespace Database\Seeders;

/** Permissions for: Course registration and lecturer course assignments. */
class CourseRegistrationPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Course registrations',
            'Registration settings',
            'Course assignments',
        ];
    }
}
