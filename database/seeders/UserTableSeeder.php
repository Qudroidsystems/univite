<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Access\HeadRoles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserTableSeeder extends Seeder
{
    /**
     * Creates the Super Admin / Admin roles and the first administrator.
     * Safe to re-run: nothing is duplicated and an existing password is never reset.
     * Set ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD in .env to customise.
     * Also creates the roles heads of department, faculty and unit get for their own area (only if missing, so
     * changes made on the Roles page are kept), and gives every current head theirs.
     */
    public function run(HeadRoles $heads): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'], ['badge' => 'badge bg-success']);
        $admin      = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['badge' => 'badge bg-primary']);

        // Students get this role when they are admitted; it carries no staff permissions.
        Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web'], ['badge' => 'badge bg-info']);

        $allPermissions = Permission::pluck('name')->all();
        $superAdmin->syncPermissions($allPermissions);
        $admin->syncPermissions($allPermissions);

        $user = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'eliabsiji@gmail.com')],
            [
                'name'     => env('ADMIN_NAME', 'System Administrator'),
                'password' => env('ADMIN_PASSWORD', '12345678'),
            ]
        );

        if (! $user->hasRole($superAdmin->name)) {
            $user->assignRole($superAdmin);
        }

        $headRoles = [
            'Head of Department' => ['View students', 'View student results', 'View course registrations', 'Approve course registrations', 'View leave applications', 'View staff records'],
            'Dean' => ['View students', 'View student results', 'View course registrations', 'View leave applications', 'View staff records'],
            'Head of Unit' => ['View leave applications', 'View staff records'],
        ];
        foreach ($headRoles as $name => $permissions) {
            if (! Role::where('name', $name)->exists()) {
                Role::create(['name' => $name, 'guard_name' => 'web', 'badge' => 'badge bg-secondary'])
                    ->syncPermissions(array_values(array_intersect($permissions, $allPermissions)));
            }
        }
        $this->laterGrants($allPermissions);
        $heads->syncAll();
    }

    /**
     * Permissions added to the head roles after they were first created. Each set is given once (remembered in
     * settings), so an administrator who later takes one away does not get it back on the next seed.
     */
    private function laterGrants(array $allPermissions): void
    {
        $grants = [
            'heads.grant.result_approval' => [
                'Head of Department' => ['Approve results', 'Waive carryover courses'],
                'Dean' => ['Approve results'],
            ],
        ];
        $grants['heads.grant.define_courses'] = ['Head of Department' => ['Define courses for lecturers']];
        $grants['heads.grant.compute_results'] = ['Head of Department' => ['Compute results']];
        foreach ($grants as $key => $roles) {
            if (\App\Models\Setting::get($key)) {
                continue;
            }
            foreach ($roles as $name => $permissions) {
                Role::where('name', $name)->first()?->givePermissionTo(array_values(array_intersect($permissions, $allPermissions)));
            }
            \App\Models\Setting::put($key, '1');
        }
    }
}
