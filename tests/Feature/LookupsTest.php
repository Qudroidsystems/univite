<?php

use App\Models\Programme;
use App\Services\Lookups\LookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => campusWorld($this));

it('serves every dropdown list to a signed-in person and nothing to a guest', function () {
    $this->getJson(route('lookups', 'departments'))->assertUnauthorized();

    foreach (app(LookupService::class)->lists() as $list) {
        $this->actingAs($this->admin)->getJson(route('lookups', $list))->assertOk()->assertJsonStructure(['options']);
    }
    $this->actingAs($this->admin)->getJson(route('lookups', 'students'))->assertNotFound();
});

it('narrows a list by its parent, groups departments by faculty, and can leave out inactive rows', function () {
    $this->actingAs($this->admin);

    $departments = $this->getJson(route('lookups', 'departments'))->json('options');
    expect($departments)->toContain(['value' => (string) $this->dept2->id, 'label' => 'Chemistry', 'group' => 'Faculty of Science']);

    $inScience = $this->getJson(route('lookups', ['list' => 'departments', 'parent' => $this->faculty2->id]))->json('options');
    expect(collect($inScience)->pluck('label')->all())->toBe(['Chemistry']);

    Programme::whereKey($this->prog2->id)->update(['is_active' => false]);
    expect(collect($this->getJson(route('lookups', 'programmes'))->json('options'))->pluck('label'))->toContain('B.Sc. Chemistry (BSC-CHM)')
        ->and(collect($this->getJson(route('lookups', ['list' => 'programmes', 'active' => 1]))->json('options'))->pluck('label'))->not->toContain('B.Sc. Chemistry (BSC-CHM)')
        ->and(collect($this->getJson(route('lookups', ['list' => 'programmes', 'q' => 'Educ']))->json('options'))->pluck('label')->all())->toBe(['B.Ed. Chemistry Education (BED-CHE)']);
});

it('marks the dependent dropdowns on the forms so they load when opened', function () {
    $page = $this->actingAs($this->admin)->get(route('academics.departments.index'))->assertOk();
    $page->assertSee('data-lookup="faculties"', false)->assertSee('univite-lookups.js', false);

    $this->get(route('bills.structure.index'))->assertOk()
        ->assertSee('data-lookup="departments" data-lookup-parent="faculty_id"', false)
        ->assertSee('data-lookup="programmes" data-lookup-parent="department_id"', false);
});
