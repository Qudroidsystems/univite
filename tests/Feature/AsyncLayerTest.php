<?php

use App\Models\Faculty;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
});

it('answers the connection probe with an empty, uncached 204', function () {
    $this->get(route('ping'))->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
});

it('turns a successful ajax post into json instead of a redirect', function () {
    $this->actingAs($this->admin)->postJson(route('academics.faculties.store'), ['name' => 'Faculty of Arts', 'code' => 'ART'], ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()->assertJson(['ok' => true, 'message' => 'Faculty created successfully.']);
});

it('returns validation errors as 422 json for ajax requests', function () {
    Faculty::create(['name' => 'Arts', 'code' => 'ART']);
    $this->actingAs($this->admin)->post(route('academics.faculties.store'), ['name' => 'Other', 'code' => 'ART'], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('code');
});

it('still redirects ordinary form posts', function () {
    $this->actingAs($this->admin)->post(route('academics.faculties.store'), ['name' => 'Faculty of Arts', 'code' => 'ART'])->assertRedirect();
});

it('ignores a repeated submission carrying the same idempotency key', function () {
    $h = ['X-Requested-With' => 'XMLHttpRequest', 'X-Idempotency-Key' => 'abcd-1234-efgh'];
    $this->actingAs($this->admin);
    $first = $this->post(route('academics.faculties.store'), ['name' => 'Faculty of Arts', 'code' => 'ART'], $h);
    $second = $this->post(route('academics.faculties.store'), ['name' => 'Faculty of Arts', 'code' => 'ART'], $h);

    $first->assertOk();
    $second->assertOk();
    expect(Faculty::count())->toBe(1);
});
