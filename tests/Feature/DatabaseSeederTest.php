<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

class CountingOnceSeeder extends Seeder
{
    public static int $runs = 0;

    public function run(): void
    {
        self::$runs++;
    }
}

class BrokenOnceSeeder extends Seeder
{
    public function run(): void
    {
        throw new RuntimeException('boom');
    }
}

class DuplicateSeeder extends Seeder
{
    public function run(): void
    {
        throw new RuntimeException('A role `Super Admin` already exists for guard `web`. (RoleAlreadyExists)');
    }
}

class WipingSeeder extends Seeder
{
    public static int $runs = 0;

    public function run(): void
    {
        self::$runs++;
    }
}

class SeederUnderTest extends DatabaseSeeder
{
    protected function sections(): array
    {
        return parent::sections() + ['Extra' => ['once', [CountingOnceSeeder::class => 'counting', BrokenOnceSeeder::class => 'broken', 'NoSuchSeeder' => 'missing',
            DuplicateSeeder::class => 'duplicate', WipingSeeder::class => 'wiping']]];
    }

    protected function guardedSeeders(): array
    {
        return [WipingSeeder::class => 'users']; // users already has the administrator, so this must not run
    }
}

it('runs repeatable seeders every time, one-off seeders once, protects tables that have data, and carries on past missing, duplicate or failing ones', function () {
    CountingOnceSeeder::$runs = 0;
    WipingSeeder::$runs = 0;
    \App\Models\User::factory()->create();
    $seed = fn () => (new SeederUnderTest)->setContainer(app())->__invoke();
    $seed();
    $seed();

    expect(CountingOnceSeeder::$runs)->toBe(1)
        ->and(Permission::count())->toBeGreaterThan(100)
        ->and(DB::table('seeder_log')->where('seeder_name', CountingOnceSeeder::class)->value('success'))->toBeTruthy()
        ->and(DB::table('seeder_log')->where('seeder_name', BrokenOnceSeeder::class)->value('error_message'))->toBe('boom')
        ->and(DB::table('seeder_log')->where('seeder_name', 'Database\Seeders\PermissionSeeder')->exists())->toBeTrue()
        ->and(DB::table('seeder_log')->where('seeder_name', DuplicateSeeder::class)->value('success'))->toBeTruthy()
        ->and(WipingSeeder::$runs)->toBe(0)
        ->and(DB::table('seeder_log')->where('seeder_name', WipingSeeder::class)->value('error_message'))->toBe('Skipped: users already had data')
        ->and(\Spatie\Permission\Models\Role::findByName('Super Admin')->permissions()->count())->toBe(Permission::count());
});
