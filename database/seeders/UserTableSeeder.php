<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserTableSeeder extends Seeder
{
    /**
     * Creates the Super Admin / Admin roles and the first administrator.
     * Safe to re-run: nothing is duplicated and an existing password is never reset.
     * Set ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD in .env to customise.
     */
    public function run(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web'], ['badge' => 'badge bg-success']);
        $admin      = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web'], ['badge' => 'badge bg-primary']);

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
    }
}
