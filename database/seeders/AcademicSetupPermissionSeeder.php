<?php

namespace Database\Seeders;

/** Permissions for: Faculties, departments, programmes, courses and the reference lists behind them. */
class AcademicSetupPermissionSeeder extends ModulePermissionSeeder
{
    public function groups(): array
    {
        return [
            'Semesters',
            'Faculties',
            'Departments',
            'Study categories',
            'Semester types',
            'Custom fields',
            'Grade bands',
            'Result keys',
            'Degree classes',
            'Student statuses',
            'Entry modes',
            'Reference lists',
            'Programmes',
            'Courses',
            'Programme curriculum',
            'Academic rules',
        ];
    }
}
