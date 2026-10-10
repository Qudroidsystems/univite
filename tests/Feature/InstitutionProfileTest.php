<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Institution;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    Storage::fake('public');
});

it('saves the institution profile with a logo and shows it across the portal', function () {
    $this->actingAs($this->admin)->get(route('institution.edit'))->assertOk()->assertSee('Full name of the institution');

    $this->put(route('institution.update'), [
        'name' => 'Adeyemi Federal University of Education', 'short_name' => 'AFUED', 'motto' => 'Knowledge for service',
        'address' => 'Ondo Road', 'city' => 'Ondo', 'state' => 'Ondo State', 'country' => 'Nigeria', 'phone' => '0800 000 0000',
        'registrar_name' => 'Dr. A. Registrar', 'primary_color' => '#0a7d3b', 'home_nationality' => 'Nigerian',
        'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
    ])->assertSessionHas('success');

    $logo = Institution::get('logo');
    Storage::disk('public')->assertExists($logo);
    expect(Setting::get('institution.short_name'))->toBe('AFUED')->and(Institution::addressLine())->toBe('Ondo Road, Ondo, Ondo State, Nigeria');

    $this->get(route('dashboard'))->assertOk()->assertSee('AFUED')->assertSee($logo)->assertSee('--vz-primary: #0a7d3b', false);
    auth()->logout();
    $this->get('/')->assertOk()->assertSee('Adeyemi Federal University of Education')->assertSee('Knowledge for service')->assertSee('0800 000 0000');

    // a new logo replaces the old one (the old file is kept so the change can be reversed); "remove" clears it
    $this->actingAs($this->admin)->put(route('institution.update'), ['name' => 'AFUED Ondo', 'logo' => UploadedFile::fake()->image('new.png')]);
    expect(Institution::get('logo'))->not->toBe($logo);
    Storage::disk('public')->assertExists($logo);
    $this->put(route('institution.update'), ['name' => 'AFUED Ondo', 'remove_logo' => 1]);
    expect(Institution::get('logo'))->toBeNull();
});

it('validates the profile and needs permission to change it', function () {
    $this->actingAs($this->admin)->put(route('institution.update'), ['name' => '', 'primary_color' => 'green', 'email' => 'nope'])
        ->assertSessionHasErrors(['name', 'primary_color', 'email']);

    $user = User::factory()->create();
    $this->actingAs($user)->get(route('institution.edit'))->assertForbidden();
    $user->givePermissionTo('View institution profile');
    $this->get(route('institution.edit'))->assertOk()->assertDontSee('ri-save-line');
    $this->put(route('institution.update'), ['name' => 'Hacked'])->assertForbidden();
});
