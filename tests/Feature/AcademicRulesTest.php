<?php

use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\SemesterType;
use App\Models\Setting;
use App\Models\User;
use App\Support\AcademicRules;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $this->faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $this->dept = Department::create(['faculty_id' => $this->faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $this->other = Department::create(['faculty_id' => $this->faculty->id, 'name' => 'Arts Education', 'code' => 'AED']);
    $this->programme = Programme::create(['department_id' => $this->dept->id, 'name' => 'B.Ed. Biology', 'code' => 'BED-BIO', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $this->second = SemesterType::where('sort_order', 2)->first();
    $this->rules = app(AcademicRules::class);
});

it('takes the most specific value: university, faculty, department, programme, then level and semester', function () {
    Setting::put('registration.min_units', '15');
    $this->rules->save('faculty', $this->faculty->id, null, null, ['min_units' => 12, 'probation_cgpa' => 1.5], null);
    $this->rules->save('department', $this->dept->id, null, null, ['max_units' => 30], null);
    $this->rules->save('programme', $this->programme->id, 400, null, ['min_units' => 6], null);
    $this->rules->save('programme', $this->programme->id, 400, $this->second->id, ['max_units' => 10], null);

    $r = $this->rules->resolve('programme', $this->programme->id);
    expect($r['min_units'])->toBe(['value' => 12, 'from' => 'Faculty of Education'])
        ->and($r['max_units']['from'])->toBe('Science Education')
        ->and($r['probation_cgpa']['value'])->toBe(1.5);

    $r = $this->rules->resolve('programme', $this->programme->id, 400, $this->second->id);
    expect($r['min_units']['value'])->toBe(6)->and($r['max_units']['value'])->toBe(10)
        ->and($r['max_units']['from'])->toContain('B.Ed. Biology, 400 level');

    // the other department only sees the faculty's override
    expect($this->rules->resolve('department', $this->other->id)['max_units']['value'])->toBe(24);

    // an empty save removes the override
    $this->rules->save('faculty', $this->faculty->id, null, null, [], null);
    expect($this->rules->resolve('programme', $this->programme->id)['min_units']['value'])->toBe(15);
});

it('lets an admin edit any scope and the university defaults, and refuses a maximum below the minimum', function () {
    $this->actingAs($this->admin)->get(route('academics.rules.index'))->assertOk()->assertSee('Minimum units per semester');
    $this->put(route('academics.rules.update'), ['scope' => 'university', 'id' => 0, 'rules' => array_merge(
        collect(AcademicRules::DEFS)->map(fn ($d) => $d[2] === 'bool' ? '1' : ($d[2] === 'choice' ? 'latest' : ''))->all(),
        ['min_units' => 10, 'max_units' => 26, 'cgpa_method' => 'all'])])->assertSessionHas('success');
    expect(Setting::get('registration.min_units'))->toBe('10')->and(Setting::get('rules.cgpa_method'))->toBe('all');

    $this->put(route('academics.rules.update'), ['scope' => 'department', 'id' => $this->dept->id, 'rules' => ['max_units' => 8]])->assertSessionHasErrors('rules.max_units');
    $this->put(route('academics.rules.update'), ['scope' => 'department', 'id' => $this->dept->id, 'rules' => ['max_units' => 20, 'min_units' => '', 'require_core' => '0']])->assertSessionHas('success');
    $own = app(AcademicRules::class)->own('department', $this->dept->id, null, null);
    expect($own)->toBe(['max_units' => 20, 'require_core' => false]);

    $this->get(route('academics.rules.index', ['scope' => 'department', 'id' => $this->dept->id]))->assertOk()->assertSee('Inherited')->assertSee('All overrides');
});

it('lets a head of department, department administrator or dean edit only their own scopes', function () {
    Role::create(['name' => 'HOD'])->givePermissionTo('Edit department academic rules');
    $hod = User::factory()->create();
    $hod->assignRole('HOD');
    $this->dept->update(['hod_id' => $hod->id]);

    $this->actingAs($hod)->get(route('academics.rules.index'))->assertOk()->assertSee('Science Education')->assertDontSee('Arts Education')->assertDontSee('Whole university');
    $this->put(route('academics.rules.update'), ['scope' => 'programme', 'id' => $this->programme->id, 'rules' => ['graduation_units' => 120]])->assertSessionHas('success');
    $this->put(route('academics.rules.update'), ['scope' => 'department', 'id' => $this->other->id, 'rules' => ['max_units' => 20]])->assertForbidden();
    $this->put(route('academics.rules.update'), ['scope' => 'university', 'id' => 0, 'rules' => ['max_units' => 20]])->assertForbidden();
    $this->put(route('academics.rules.update'), ['scope' => 'faculty', 'id' => $this->faculty->id, 'rules' => ['max_units' => 20]])->assertForbidden();

    $clerk = User::factory()->create();
    $clerk->assignRole('HOD');
    $this->actingAs($clerk)->put(route('academics.rules.update'), ['scope' => 'department', 'id' => $this->other->id, 'rules' => ['max_units' => 20]])->assertForbidden();
    $this->actingAs($hod)->post(route('academics.rules.admins', $this->other->id), ['user_id' => $clerk->id])->assertForbidden();
    $this->actingAs($this->admin)->post(route('academics.rules.admins', $this->other->id), ['user_id' => $clerk->id])->assertSessionHas('success');
    $this->actingAs($clerk)->put(route('academics.rules.update'), ['scope' => 'department', 'id' => $this->other->id, 'rules' => ['max_units' => 20]])->assertSessionHas('success');

    $dean = User::factory()->create();
    $dean->assignRole('HOD');
    $this->faculty->update(['dean_id' => $dean->id]);
    $this->actingAs($dean)->put(route('academics.rules.update'), ['scope' => 'faculty', 'id' => $this->faculty->id, 'rules' => ['probation_cgpa' => 1.5]])->assertSessionHas('success');
    $this->put(route('academics.rules.update'), ['scope' => 'department', 'id' => $this->other->id, 'level' => 400, 'rules' => ['min_units' => 6]])->assertSessionHas('success');

    $nobody = User::factory()->create();
    $this->actingAs($nobody)->get(route('academics.rules.index'))->assertForbidden();
});
