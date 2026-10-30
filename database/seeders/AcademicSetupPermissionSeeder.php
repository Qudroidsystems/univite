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
            'Result signatories',
            'Degree classes',
            'Student statuses',
            'Entry modes',
            'Department categories',
            'Sub-departments',
            'Course options',
            'Reference lists',
            'Programmes',
            'Courses',
            'Programme curriculum',
            'Academic rules',
        ];
    }
}
