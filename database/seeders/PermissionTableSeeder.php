<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionTableSeeder extends Seeder
{
    /**
     * Permission groups. The key is the group title shown in the role editor;
     * each entry is a permission name. Safe to re-run: existing rows are updated.
     */
    public static function groups(): array
    {
        $crud = fn (string $x) => ["View $x", "Create $x", "Update $x", "Delete $x"];

        return [
            'Dashboard'            => ['dashboard'],
            'User Management'      => $crud('user'),
            'Role Management'      => array_merge($crud('role'), ['Add user-role', 'Update user-role', 'Remove user-role']),
            'Permission Management' => $crud('permission'),

            // ---- Academic setup ----
            'Academic Sessions'    => array_merge($crud('session'), ['Set current session']),
            'Semesters'            => array_merge($crud('semester'), ['Set current semester']),
            'Faculties'            => $crud('faculty'),
            'Departments'          => $crud('department'),
            'Programmes'           => $crud('programme'),
            'Courses'              => $crud('course'),
            'Curriculum'           => ['View curriculum', 'Update curriculum'],

            // ---- Bills ----
            'Bill Items'           => $crud('bill-item'),
            'School Bills'         => array_merge($crud('bill'), ['Copy bills']),
        ];
    }

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::groups() as $title => $names) {
            foreach ($names as $name) {
                Permission::updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['title' => $title]
                );
            }
        }
    }
}
