<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionTableSeeder extends Seeder
{
    /**
     * Safe to re-run. Brings the permissions table in line with App\Support\PermissionCatalog:
     *  1. permissions that were renamed are moved to their new name (assignments are kept),
     *  2. every catalogued permission is created or updated with its group and description.
     * Custom permissions that are not in the catalog are left alone.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->applyRenames();

        foreach (PermissionCatalog::all() as $name => $meta) {
            Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['title' => $meta['group'], 'description' => $meta['description']]
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function applyRenames(): void
    {
        $alsoGranted = PermissionCatalog::alsoGranted();

        foreach (PermissionCatalog::renamed() as $oldName => $newName) {
            $old = Permission::where('name', $oldName)->where('guard_name', 'web')->first();
            if (! $old) {
                continue;
            }

            $new = Permission::firstOrCreate(['name' => $newName, 'guard_name' => 'web']);

            // Hand the old permission's holders the new one (and any it used to cover), then retire the old row.
            foreach ($old->roles as $role) {
                $role->givePermissionTo($new, ...$this->extra($alsoGranted[$oldName] ?? []));
            }
            foreach ($old->users as $user) {
                $user->givePermissionTo($new, ...$this->extra($alsoGranted[$oldName] ?? []));
            }

            $old->delete();
        }
    }

    /** @return array<int, Permission> */
    private function extra(array $names): array
    {
        return array_map(
            fn ($n) => Permission::firstOrCreate(['name' => $n, 'guard_name' => 'web']),
            $names
        );
    }
}
