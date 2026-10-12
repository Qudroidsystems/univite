<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds every permission in the portal. Safe to re-run (php artisan db:seed --class=PermissionSeeder):
 *  1. permissions that were renamed are moved to their new name (assignments are kept),
 *  2. the core permissions (dashboard, users, roles, permissions) are seeded here,
 *  3. each module's named seeder in MODULES seeds its own permissions.
 * Every permission's name, group and description lives in App\Support\PermissionCatalog.
 * Custom permissions that are not in the catalog are left alone.
 */
class PermissionSeeder extends ModulePermissionSeeder
{
    /** One named seeder per module. A new module's seeder goes here. */
    public const MODULES = [
        InstitutionPermissionSeeder::class,
        AcademicSetupPermissionSeeder::class,
        CourseRegistrationPermissionSeeder::class,
        ResultPermissionSeeder::class,
        AdmissionPermissionSeeder::class,
        FinancePermissionSeeder::class,
        VenueTimetablePermissionSeeder::class,
        StaffPermissionSeeder::class,
        PayrollPermissionSeeder::class,
        LeavePermissionSeeder::class,
        MessagingPermissionSeeder::class,
        PollPermissionSeeder::class,
        IdCardPermissionSeeder::class,
        LibraryPermissionSeeder::class,
        HealthPermissionSeeder::class,
        AccommodationPermissionSeeder::class,
        TeachingPracticePermissionSeeder::class,
        CampusLifePermissionSeeder::class,
    ];

    public function groups(): array
    {
        return [
            'Dashboard',
            'Users',
            'Roles',
            'Permissions',
        ];
    }

    public function run(PermissionRegistrar $registrar): void
    {
        $registrar->forgetCachedPermissions();

        $this->applyRenames();

        parent::run($registrar);

        $this->call(self::MODULES);
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
