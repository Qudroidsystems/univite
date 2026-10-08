<?php

use App\Models\EntryMode;
use App\Models\Lookup;
use App\Models\SemesterType;
use App\Models\StudentStatus;
use App\Models\StudyCategory;
use App\Models\User;
use App\Support\Levels;
use App\Support\Lookups;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    StudentStatus::flush();
    Lookups::flush();
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
});

it('seeds sensible defaults a university can edit', function () {
    expect(Lookups::options('gender'))->toHaveKeys(['male', 'female'])
        ->and(Lookups::options('state'))->toHaveKey('Ondo')
        ->and(SemesterType::options())->toBe([1 => 'First Semester', 2 => 'Second Semester'])
        ->and(StudentStatus::defaultCode())->toBe('active')
        ->and(EntryMode::byCode('direct_entry')->entry_level)->toBe(200);
});

it('formats levels per study category', function () {
    $nce = StudyCategory::create(['name' => 'NCE', 'code' => 'NCE', 'duration_years' => 3, 'level_format' => 'NCE {N}']);
    expect(Levels::label(200, $nce))->toBe('NCE 2')
        ->and(Levels::label(100, null))->toBe('100 Level');
});

it('lets an admin add a semester type and new sessions use it', function () {
    $this->actingAs($this->admin)->post(route('academics.semester-types.store'), ['sort_order' => 3, 'name' => 'Third Semester', 'is_teaching' => 1, 'is_active' => 1])->assertSessionHasNoErrors();
    expect(SemesterType::options())->toHaveKey(3);
});

it('applies the can-login switch of a status to students', function () {
    $this->actingAs($this->admin)->post(route('academics.student-statuses.store'), [
        'code' => 'probation', 'name' => 'Probation', 'badge' => 'warning', 'sort_order' => 9,
        'can_login' => 0, 'can_register' => 0, 'can_pay' => 1, 'is_enrolled' => 1, 'is_active' => 1,
    ])->assertSessionHasNoErrors();
    expect(StudentStatus::codesWhere('can_login'))->not->toContain('probation')
        ->and(StudentStatus::label('probation'))->toBe('Probation');
});

it('refuses to delete the default status and system list items', function () {
    $default = StudentStatus::byCode('active');
    $this->actingAs($this->admin)->delete(route('academics.student-statuses.destroy', $default->id))->assertSessionHas('error');
    expect(StudentStatus::byCode('active'))->not->toBeNull();

    $system = Lookup::where('is_system', true)->first();
    if ($system) {
        $this->actingAs($this->admin)->delete(route('academics.lists.destroy', $system->id))->assertSessionHas('error');
        expect(Lookup::find($system->id))->not->toBeNull();
    }
});

it('adds an option to a reference list', function () {
    $this->actingAs($this->admin)->post(route('academics.lists.store'), ['list' => 'title', 'code' => 'Barr.', 'label' => 'Barrister', 'sort_order' => 50, 'is_active' => 1])->assertSessionHasNoErrors();
    expect(Lookups::options('title'))->toHaveKey('Barr.');
});

it('keeps entry-mode rules in data', function () {
    expect(EntryMode::options(true))->not->toHaveKey('transfer');
    EntryMode::byCode('transfer')->update(['open_online' => true]);
    expect(EntryMode::options(true))->toHaveKey('transfer');
});
