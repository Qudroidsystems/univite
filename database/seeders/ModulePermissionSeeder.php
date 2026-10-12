<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Base for the named permission seeders (LeavePermissionSeeder, LibraryPermissionSeeder ...).
 * Each one seeds the permission groups it names from App\Support\PermissionCatalog, which holds every permission's
 * name, group and description. Safe to re-run: existing rows are updated, custom permissions are left alone.
 *
 * To add a permission, add it to its group in PermissionCatalog. To add a module, create a seeder like the others
 * and list it in PermissionSeeder::MODULES.
 */
abstract class ModulePermissionSeeder extends Seeder
{
    /** @return string[] PermissionCatalog group names this seeder owns */
    abstract public function groups(): array;

    public function run(PermissionRegistrar $registrar): void
    {
        foreach ($this->groups() as $group) {
            foreach (PermissionCatalog::groups()[$group]['permissions'] ?? [] as [$name, , $description]) {
                Permission::updateOrCreate(
                    ['name' => $name, 'guard_name' => 'web'],
                    ['title' => $group, 'description' => $description]
                );
            }
        }

        $registrar->forgetCachedPermissions();
    }
}
