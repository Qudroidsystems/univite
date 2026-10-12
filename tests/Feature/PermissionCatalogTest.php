<?php

use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('seeds every permission with a group and a real description', function () {
    $this->seed(PermissionSeeder::class);

    expect(Permission::count())->toBe(count(PermissionCatalog::all()));

    foreach (Permission::all() as $permission) {
        expect($permission->title)->not->toBeEmpty("{$permission->name} has no group")
            ->and(strlen((string) $permission->description))->toBeGreaterThanOrEqual(30, "{$permission->name} needs a proper description");
    }
});

it('uses clear names: no technical slugs, and every name starts with a verb', function () {
    foreach (array_keys(PermissionCatalog::all()) as $name) {
        expect($name)->not->toMatch('/[-_]/')
            ->and($name)->toMatch('/^(View|Add|Create|Edit|Delete|Assign|Remove|Reset|Disable|Set|Copy|Admit|Reject|Change|Generate|Record|Cancel|Verify|Clear|Restore|Import|Undo|Approve|Enter|Publish|Amend|Respond|Reverse|Withdraw|Resend|Prepare|Mark|Issue|Waive|Allocate|Check|Post) /');
    }
});

it('keeps every permission checked in code in the catalog (no typos, nothing undocumented)', function () {
    $used = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    $views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

    foreach ([$files, $views] as $iterator) {
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            if (str_ends_with($file->getFilename(), 'PermissionCatalog.php')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());

            // middleware('permission:A|B'), can('A'), canany(['A','B']), ['label', 'route', 'A', ...] sidebar rows
            preg_match_all("/(?:permission|scoped):([^'\"]+)['\"]/", $source, $m);
            foreach ($m[1] as $list) {
                foreach (explode('|', $list) as $name) {
                    if (preg_match('/\\s$|^(View|Create|Edit|Delete)$/', $name)) {
                        continue; // built at runtime from a controller's $permission slug
                    }
                    $used[trim($name)] = $file->getFilename();
                }
            }
            preg_match_all("/@?can(?:any)?\(\[?\s*'([^']+)'(?:\s*,\s*'([^']+)')*/", $source, $m2, PREG_SET_ORDER);
            foreach ($m2 as $match) {
                foreach (array_slice($match, 1) as $name) {
                    if ($name !== '' && ! preg_match('/\\s$|^(View|Create|Edit|Delete)$/', $name)) {
                        $used[$name] = $file->getFilename();
                    }
                }
            }
            // Permission names written as plain strings (row actions, sidebar rows) -- not UI labels in other views
            if (str_starts_with($file->getPathname(), app_path()) || $file->getFilename() === 'sidebar.blade.php') {
                preg_match_all("/'((?:View|Add|Create|Edit|Delete|Assign|Remove|Reset|Disable|Set|Copy|Admit|Reject|Change|Generate|Record|Cancel|Verify|Clear|Restore|Import|Undo|Approve|Enter|Publish|Amend) [a-z]+(?: [a-z]+)*)'/", $source, $m3);
                foreach ($m3[1] as $name) {
                    $used[$name] = $file->getFilename();
                }
            }
        }
    }

    // Resource controllers build "View {noun}" from a $permission property
    foreach (glob(app_path('Http/Controllers/Academics/*Controller.php')) as $file) {
        if (preg_match("/\\\$permission = '([^']+)'/", file_get_contents($file), $m)) {
            $source = file_get_contents($file);
            $verbs = ['View' => true, 'Create' => true, 'Edit' => true, 'Delete' => true];
            if (preg_match("/\\\$actions = \\[([^\\]]*)\\]/", $source, $a)) {
                preg_match_all("/'(\\w+)'/", $a[1], $names);
                $verbs = ['View' => true] + array_filter([
                    'Create' => in_array('create', $names[1]), 'Edit' => in_array('edit', $names[1]), 'Delete' => in_array('delete', $names[1]),
                ]);
            }
            foreach (array_keys($verbs) as $verb) {
                $used["$verb {$m[1]}"] = basename($file);
            }
        }
    }

    $unknown = array_diff_key($used, PermissionCatalog::all());
    expect($unknown)->toBe([], 'Permissions used in code but missing from the catalog: ' . implode(', ', array_keys($unknown)));
    expect(count($used))->toBeGreaterThan(40);
});

it('carries existing role and user assignments across when permissions are renamed', function () {
    $oldView   = Permission::create(['name' => 'View session', 'guard_name' => 'web']);
    $oldUpdate = Permission::create(['name' => 'Update user', 'guard_name' => 'web']);
    $oldDash   = Permission::create(['name' => 'dashboard', 'guard_name' => 'web']);

    $role = Role::create(['name' => 'Registrar']);
    $role->givePermissionTo($oldView, $oldUpdate);
    $user = User::factory()->create();
    $user->givePermissionTo($oldDash);

    $this->seed(PermissionSeeder::class);

    expect(Permission::where('name', 'View session')->exists())->toBeFalse()
        ->and(Permission::where('name', 'dashboard')->exists())->toBeFalse();

    $role = $role->fresh();
    expect($role->hasPermissionTo('View academic sessions'))->toBeTrue()
        ->and($role->hasPermissionTo('Edit users'))->toBeTrue()
        // "Update user" used to cover these two, so its holders keep them
        ->and($role->hasPermissionTo('Reset user passwords'))->toBeTrue()
        ->and($role->hasPermissionTo('Disable or enable user accounts'))->toBeTrue()
        ->and($user->fresh()->hasPermissionTo('View dashboard'))->toBeTrue();
});

it('is safe to seed twice and keeps custom permissions', function () {
    Permission::create(['name' => 'Approve hostel allocations', 'guard_name' => 'web']);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Permission::count())->toBe(count(PermissionCatalog::all()) + 1)
        ->and(Permission::where('name', 'Approve hostel allocations')->exists())->toBeTrue();
});

it('explains permissions in the role editor, grouped, with group descriptions', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->first();

    $this->actingAs($admin)->get(route('roles.index'))
        ->assertOk()
        ->assertSee('Set the current academic session')
        ->assertSee('Choose which session the whole portal treats as the present one', false)
        ->assertSee('A session is one academic year', false)
        ->assertSee('Copy a whole session', false);
});

it('shows custom permissions with a sensible fallback description', function () {
    $this->seed(DatabaseSeeder::class);
    Permission::create(['name' => 'Approve hostel allocations', 'guard_name' => 'web', 'title' => 'Hostels', 'description' => 'Sign off hostel room allocations.']);

    expect(\App\Support\PermissionMeta::for('Approve hostel allocations')['desc'])->toBe('Sign off hostel room allocations.')
        ->and(\App\Support\PermissionMeta::for('Edit courses')['action'])->toBe('Edit');
});

it('lets separate permissions control resetting passwords and disabling accounts', function () {
    $this->seed(DatabaseSeeder::class);
    $target = User::factory()->create();
    $helper = User::factory()->create();
    $helper->givePermissionTo(['View users', 'Reset user passwords']);

    $this->actingAs($helper)->post(route('users.reset-password', $target))->assertSessionHas('generated_password');
    $this->actingAs($helper)->post(route('users.toggle-status', $target))->assertForbidden();
    $this->actingAs($helper)->post(route('users.store'), ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();
});

it('gives every permission group exactly one named seeder', function () {
    $owners = [];
    foreach ([PermissionSeeder::class, ...PermissionSeeder::MODULES] as $seeder) {
        expect(class_basename($seeder))->toEndWith('PermissionSeeder');
        foreach ((new $seeder)->groups() as $group) {
            expect(array_key_exists($group, PermissionCatalog::groups()))->toBeTrue("{$seeder} names an unknown group {$group}");
            $owners[$group][] = class_basename($seeder);
        }
    }

    foreach (array_keys(PermissionCatalog::groups()) as $group) {
        expect($owners[$group] ?? [])->toHaveCount(1, "Group {$group} must be seeded by exactly one named seeder");
    }
});

it('lets one module seeder run on its own', function () {
    $this->seed(\Database\Seeders\LeavePermissionSeeder::class);

    expect(Permission::pluck('title')->unique()->values()->all())->toBe(['Staff leave'])
        ->and(Permission::count())->toBe(count(PermissionCatalog::groups()['Staff leave']['permissions']));
});
