<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('Super Admin');
});

it('sends the old role and permission form pages to the pages that hold their forms', function () {
    $role = Role::where('name', 'Admin')->firstOrFail();
    $permission = Permission::firstOrFail();

    $this->actingAs($this->admin);
    $this->get(route('roles.create'))->assertRedirect(route('roles.index'));
    $this->get(route('roles.edit', $role->id))->assertRedirect(route('roles.show', $role->id));
    $this->get(route('roles.edit', 999999))->assertNotFound();
    $this->get(route('permissions.create'))->assertRedirect(route('permissions.index'));
    $this->get(route('permissions.show', $permission->id))->assertRedirect(route('permissions.index'));
    $this->get(route('permissions.edit', $permission->id))->assertRedirect(route('permissions.index'));

    $this->get(route('roles.index'))->assertOk();
    $this->get(route('roles.show', $role->id))->assertOk();
    $this->get(route('permissions.index'))->assertOk();
});
