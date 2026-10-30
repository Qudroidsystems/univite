<?php

use App\Models\CourseOption;
use App\Models\DepartmentCategory;
use App\Models\MatricFormat;
use App\Models\Programme;
use App\Models\StudyCategory;
use App\Models\SubDepartment;
use App\Services\MatricNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    campusWorld($this);
    $this->nce = StudyCategory::firstOrCreate(['code' => 'NCE'], ['name' => 'NCE', 'duration_years' => 3]);
    $this->prog->update(['study_category_id' => $this->nce->id]);
});

it('sets up department categories, sub-departments and course options and uses them on programmes', function () {
    $this->actingAs($this->admin);
    $this->get(route('academics.department-categories.index'))->assertOk()->assertSee('Department Categories');
    $this->post(route('academics.department-categories.store'), ['department_id' => $this->dept->id, 'study_category_id' => $this->nce->id, 'lowest_level' => 100, 'highest_level' => 300, 'semesters_per_level' => 1, 'is_active' => 1])
        ->assertSessionHasNoErrors();
    // the programme now runs only 100 to 300 even though its duration is 4 years
    expect($this->prog->fresh()->levels())->toBe([100, 200, 300]);
    $this->post(route('academics.department-categories.store'), ['department_id' => $this->dept->id, 'study_category_id' => $this->nce->id, 'lowest_level' => 200, 'semesters_per_level' => 2])
        ->assertSessionHasErrors('study_category_id');

    $this->post(route('academics.sub-departments.store'), ['department_id' => $this->dept->id, 'name' => 'Biology Education', 'code' => 'bio'])->assertSessionHasNoErrors();
    $sub = SubDepartment::firstOrFail();
    expect($sub->code)->toBe('BIO');
    $this->post(route('academics.course-options.store'), ['programme_id' => $this->prog->id, 'name' => 'Acting'])->assertSessionHasNoErrors();
    expect(CourseOption::where('programme_id', $this->prog->id)->pluck('name')->all())->toBe(['Acting']);
    $this->getJson(url('lookups/course-options') . '?parent=' . $this->prog->id)->assertOk()->assertSee('Acting');

    // combined programmes need the department category to allow them
    $base = ['department_id' => $this->dept->id, 'name' => 'NCE Biology/Chemistry', 'code' => 'nce-bc', 'study_category_id' => $this->nce->id, 'degree_type' => 'NCE', 'duration_years' => 3];
    $this->post(route('academics.programmes.store'), $base + ['is_combination' => 1, 'second_department_id' => $this->dept2->id, 'is_active' => 1])->assertSessionHasErrors('is_combination');
    DepartmentCategory::firstOrFail()->update(['allow_combination' => true]);
    $this->post(route('academics.programmes.store'), $base + ['is_combination' => 1])->assertSessionHasErrors('second_department_id');
    $this->post(route('academics.programmes.store'), $base + ['is_combination' => 1, 'second_department_id' => $this->dept2->id, 'sub_department_id' => $sub->id,
        'application_name' => 'NCE Biology/Chemistry (Double Major)', 'show_on_application' => 1])->assertSessionHasNoErrors();
    $p = Programme::where('code', 'NCE-BC')->firstOrFail();
    expect($p->is_combination)->toBeTrue()->and($p->second_department_id)->toBe($this->dept2->id)->and($p->applicationLabel())->toBe('NCE Biology/Chemistry (Double Major)');
    $this->get(route('academics.programmes.index'))->assertSee('Combined')->assertSee('Applicants see: NCE Biology/Chemistry (Double Major)');
});

it('shows only programmes marked for the application form, under their application name', function () {
    $this->prog->update(['application_name' => 'NCE (Chemistry/Biology)']);
    $this->prog2->update(['show_on_application' => false]);
    foreach (['open' => '1', 'session_id' => (string) $this->session->id, 'fee' => '2000'] as $k => $v) {
        \App\Models\Setting::put("application.$k", $v);
    }
    auth()->logout();
    $this->get(route('apply.register'))->assertOk()->assertSee('NCE (Chemistry/Biology)')->assertDontSee('B.Sc. Chemistry');
});

it('uses the most specific matric format, with a prefix, a suffix by entry mode and a number range', function () {
    $gen = app(MatricNumberGenerator::class);
    MatricFormat::create(['name' => 'Science Education', 'department_id' => $this->dept->id, 'pattern' => '{PREFIX}/{YEAR}/{SEQ:4}{SUFFIX}', 'prefix' => 'SED', 'suffix' => 'U', 'serial_from' => 50]);
    MatricFormat::create(['name' => 'Science Education, direct entry', 'department_id' => $this->dept->id, 'entry_mode' => 'direct_entry', 'pattern' => '{PREFIX}/{YEAR}/{SEQ:4}{SUFFIX}', 'prefix' => 'SED', 'suffix' => 'D', 'serial_to' => 2]);

    expect($gen->next($this->prog, $this->session, 'utme'))->toBe('SED/2026/0050U')
        ->and($gen->next($this->prog, $this->session, 'utme'))->toBe('SED/2026/0051U')
        ->and($gen->next($this->prog, $this->session, 'direct_entry'))->toBe('SED/2026/0001D')
        // no format for the other department: the school-wide pattern
        ->and($gen->next($this->prog2, $this->session))->toBe('2026/BSC-CHM/0001');
    $gen->next($this->prog, $this->session, 'direct_entry');
    expect(fn () => $gen->next($this->prog, $this->session, 'direct_entry'))->toThrow(RuntimeException::class, 'ends at 2');

    $this->actingAs($this->admin)->get(route('students.matric-formats.index'))->assertOk()->assertSee('SED/2026/0052U');
    $this->post(route('students.matric-formats.store'), ['name' => 'Bad', 'pattern' => '{YEAR}/{NOPE}', 'serial_from' => 1])->assertSessionHasErrors('pattern');
});
