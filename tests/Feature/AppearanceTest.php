<?php

use App\Models\User;
use App\Support\Appearance;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
});

it('uses a larger default text size and the school\'s font on every page', function () {
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('--uv-scale: ' . round(15 / 13, 4), false)
        ->assertSee('family=Poppins', false)
        ->assertSee('univite-appearance.css', false);

    $this->put(route('appearance.update'), ['font' => 'lexend', 'size' => 17, 'heading_font' => 'same', 'theme' => 'light', 'menu' => 'side', 'density' => 'comfortable', 'line_spacing' => 'relaxed', 'users_choose_font' => 1, 'users_choose_size' => 1])
        ->assertSessionHas('success');

    $this->get(route('dashboard'))->assertSee('family=Lexend', false)->assertSee('"Lexend", sans-serif', false)->assertSee('--uv-scale: ' . round(17 / 13, 4), false)
        ->assertSee("setAttribute('data-uv-lines', \"relaxed\")", false);

    // pages for applicants and the sign-in page use the school's choice too
    auth()->logout();
    $this->get(route('login'))->assertOk()->assertSee('family=Lexend', false);
});

it('lets each person choose their own font and size, unless the school does not allow it', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('my.appearance'))->assertOk()->assertSee('Display &amp; reading', false)->assertSee('Atkinson Hyperlegible');

    $this->put(route('my.appearance.update'), ['font' => 'atkinson', 'size' => 20, 'theme' => 'dark', 'density' => 'compact', 'reduce_motion' => 1])->assertSessionHas('success');
    expect($user->fresh()->preferences['appearance'])->toMatchArray(['font' => 'atkinson', 'size' => 20, 'theme' => 'dark', 'density' => 'compact', 'reduce_motion' => true]);
    $this->get(route('dashboard'))->assertSee('Atkinson+Hyperlegible', false)->assertSee('--uv-scale: ' . round(20 / 13, 4), false)
        ->assertSee("localStorage.setItem('uv-theme', \"dark\")", false)->assertSee("setAttribute('data-uv-motion', 'reduce')", false);

    // the school switches personal fonts off: the person sees the school's font again, but keeps their size
    app(\App\Support\AppearanceSettings::class)->save(['users_choose_font' => 0]);
    $look = app(Appearance::class)->for($user->fresh());
    expect($look->font)->toBe('poppins')->and($look->size)->toBe(20);

    // bad values are refused; reset goes back to the school's settings
    $this->put(route('my.appearance.update'), ['font' => 'comic', 'size' => 99])->assertSessionHasErrors(['font', 'size']);
    $this->delete(route('my.appearance.reset'))->assertSessionHas('success');
    expect($user->fresh()->preferences['appearance'])->toBe([]);
});

it('saves the light/dark and menu switches from the top bar', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson(route('my.appearance.quick'), ['theme' => 'auto'])->assertOk();
    $this->postJson(route('my.appearance.quick'), ['menu' => 'top'])->assertOk();
    $this->postJson(route('my.appearance.quick'), ['theme' => 'purple'])->assertUnprocessable();
    expect($user->fresh()->preferences['appearance'])->toBe(['theme' => 'auto', 'menu' => 'top']);
});

it('only lets someone with the permission change the school\'s appearance', function () {
    $this->actingAs(User::factory()->create())->get(route('appearance.edit'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('appearance.edit'))->assertOk()->assertSee('Each person may choose their own font');
});

it('gives the app the school\'s look before sign-in and the person\'s own after, and saves changes from the app', function () {
    $this->getJson('/api/v1/ping')->assertOk()->assertJsonPath('data.appearance.font', 'poppins')->assertJsonPath('data.appearance.size', 15)
        ->assertJsonPath('data.appearance.font_name', 'Poppins');

    $user = User::factory()->create(['password' => 'secret-pass-1']);
    $token = $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'secret-pass-1'])->assertCreated()
        ->assertJsonPath('data.user.appearance.size', 15)->json('data.token');

    $this->withToken($token)->putJson('/api/v1/me/appearance', ['font' => 'system', 'size' => 18, 'theme' => 'dark'])->assertOk()
        ->assertJsonPath('data.current.font_name', null)->assertJsonPath('data.current.size', 18)->assertJsonPath('data.current.text_scale', round(18 / 14, 3))
        ->assertJsonPath('data.saved.theme', 'dark')->assertJsonPath('data.school.size', 15);
    $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.appearance.theme', 'dark');
    $this->withToken($token)->putJson('/api/v1/me/appearance', ['size' => 7])->assertUnprocessable();
});
