<?php

namespace Database\Seeders;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Runs every seeder in one `php artisan db:seed`, section by section, and prints a summary.
 *
 * - "always" seeders are safe to repeat (updateOrCreate / firstOrCreate) and run every time, so new permissions and
 *   roles reach existing installs after each deploy.
 * - "once" seeders run the first time only; seeder_log remembers them. Delete a row from seeder_log to run one again.
 * - A listed seeder that does not exist yet is skipped with a warning; one that fails is reported and the rest carry on.
 *   "Already exists" / duplicate-entry errors (PermissionAlreadyExists, RoleAlreadyExists) count as done.
 * - A "once" seeder that empties or overwrites a table can be listed in guardedSeeders(): on an existing install where
 *   that table already has data it is marked as run instead of being run.
 * - Super Admin is given every permission after the seeders run. Demo seeders run only in local/development.
 *
 * To add a seeder, put its class name and a message in the right section of sections().
 */
class DatabaseSeeder extends Seeder
{
    /** @var string[] fully qualified names of seeders that already ran successfully */
    protected array $ran = [];

    protected array $stats = ['run' => 0, 'skipped' => 0, 'failed' => 0];

    /**
     * Section title => [mode ('always' | 'once'), [SeederClass => message]].
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    protected function sections(): array
    {
        return [
            '🔐 PART 1: PERMISSIONS' => ['always', [
                'PermissionSeeder' => '🔐 Seeding permissions (core, then one named seeder per module)...',
            ]],
            '👤 PART 2: ROLES, HEADS AND ADMINISTRATOR' => ['always', [
                'UserTableSeeder' => '👤 Seeding roles, head roles and the administrator account...',
            ]],
            '📚 PART 3: REFERENCE DATA (first install only)' => ['once', [
                // Seeders that load starting data once, e.g. 'HostelSeeder' => '🏠 Seeding hostels...',
            ]],
        ];
    }

    /**
     * "Once" seeders that empty or overwrite a table: seeder => table. If the table already has data and the seeder has
     * no seeder_log entry (an install from before the log), it is recorded as run rather than wiping real data.
     *
     * @return array<string, string>
     */
    protected function guardedSeeders(): array
    {
        return [
            // 'HostelSeeder' => 'hostels',
        ];
    }

    /** Sample data for a development machine only; never run on a live server. @return array<string, string> */
    protected function demoSeeders(): array
    {
        return [
            // 'DemoStudentsSeeder' => '👨‍🎓 Seeding demo students...',
        ];
    }

    public function run(): void
    {
        $started = microtime(true);
        $this->loadRan();

        $this->box(['🚀 UNIVITE DATABASE SEEDING', 'Starting at: ' . now()->format('Y-m-d H:i:s')]);

        foreach ($this->guardedSeeders() as $seeder => $table) {
            $this->markAsRunIfTableHasData($seeder, $table);
        }

        foreach ($this->sections() as $title => [$mode, $seeders]) {
            if (! $seeders) {
                continue;
            }
            $this->section($title);
            foreach ($seeders as $seeder => $message) {
                $this->safeCall($seeder, $message, $mode === 'always');
            }
            $this->line('');
        }

        // Super Admin always holds every permission, including ones added above.
        $this->syncSuperAdminPermissions();

        $demo = $this->demoSeeders();
        if (app()->environment('local', 'development')) {
            if ($demo) {
                $this->section('🧪 DEMO & TEST DATA (development environment)');
                $this->warn('⚠️  Running in DEVELOPMENT mode - seeding demo data...');
                foreach ($demo as $seeder => $message) {
                    $this->safeCall($seeder, $message, false);
                }
                $this->line('');
            }
        } else {
            $this->section('🚀 PRODUCTION ENVIRONMENT');
            $this->info('Skipping demo data - only seeding essential data');
            $this->stats['skipped'] += count($demo);
            $this->line('');
        }

        $this->section('📊 DATABASE STATISTICS');
        $this->showStats();

        $this->line('');
        $this->box([
            '✅ SEEDING COMPLETED',
            '📊 Seeders run: ' . $this->stats['run'],
            '⏭️  Seeders skipped: ' . $this->stats['skipped'],
            '❌ Seeders failed: ' . $this->stats['failed'],
            '⏱️  Time: ' . round(microtime(true) - $started, 2) . ' seconds',
            '🕐 Completed at: ' . now()->format('Y-m-d H:i:s'),
        ]);

        if ($this->stats['failed'] > 0) {
            $this->warn('⚠️  Some seeders failed. See the errors above (also in storage/logs and the seeder_log table).');
            $this->warn('💡 Fix the seeder and run "php artisan db:seed" again - one-off seeders that worked will be skipped.');
        } else {
            $this->info('🎉 Database seeding completed successfully!');
        }

        // The account UserTableSeeder creates from ADMIN_EMAIL / ADMIN_PASSWORD in .env. The password only applies when the
        // account is first created; changing .env later does not change an existing password.
        $this->line('');
        $this->info('🔑 Default Super Admin login');
        $this->info('   Email    : ' . env('ADMIN_EMAIL', 'eliabsiji@gmail.com'));
        $this->info('   Password : ' . env('ADMIN_PASSWORD', '12345678') . ' (from ADMIN_PASSWORD in .env when the account was first created)');
        $this->warn('   ⚠️  Change this password after the first login on a live server.');
        $this->line('');
    }

    protected function syncSuperAdminPermissions(): void
    {
        try {
            $role = Role::where('name', 'Super Admin')->where('guard_name', 'web')->first();
            if ($role) {
                $ids = Permission::where('guard_name', 'web')->pluck('id')->all();
                $role->syncPermissions($ids);
                $this->info('  ✅ Super Admin now has all ' . count($ids) . ' permissions');
            } else {
                $this->warn('  ⚠️  Super Admin role not found - permissions not synced');
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable $e) {
            $this->warn('  ⚠️  Could not sync Super Admin permissions: ' . $e->getMessage());
        }
        $this->line('');
    }

    protected function markAsRunIfTableHasData(string $seeder, string $table): void
    {
        try {
            $class = $this->resolveSeederClass($seeder);
            if (! $this->hasBeenRun($class) && Schema::hasTable($table) && DB::table($table)->exists()) {
                $this->log($class, true, "Skipped: {$table} already had data");
            }
        } catch (\Throwable $e) {
            Log::warning("Could not check {$table} before {$seeder}: " . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- running seeders

    protected function safeCall(string $seeder, string $message, bool $always): void
    {
        $class = $this->resolveSeederClass($seeder);
        $name = class_basename($class);

        if (! class_exists($class)) {
            $this->warn("  ⚠️  Seeder not found: {$name} - skipping");
            $this->stats['skipped']++;

            return;
        }
        if (! $always && $this->hasBeenRun($class)) {
            $this->info("  ⏭️  {$name} already run - skipping");
            $this->stats['skipped']++;

            return;
        }

        $this->info($message);
        try {
            $this->call($class);
            $this->log($class, true);
            $this->info("  ✅ {$name} completed");
            $this->stats['run']++;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'PermissionAlreadyExists') || str_contains($msg, 'RoleAlreadyExists') || str_contains(strtolower($msg), 'already exists') || str_contains(strtolower($msg), 'duplicate entry')) {
                $this->log($class, true, 'Some permissions or records already existed');
                $this->warn("  ⚠️  {$name} - some records already exist, continuing");
                $this->stats['run']++;

                return;
            }
            Log::error("Seeder failed: {$class}", ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            $this->log($class, false, $e->getMessage());
            $this->error("  ❌ {$name} failed: " . $e->getMessage());
            $this->stats['failed']++;
        }
    }

    /** "PermissionSeeder" -> Database\\Seeders\\PermissionSeeder; a class from elsewhere is kept as given. */
    protected function resolveSeederClass(string $seeder): string
    {
        $local = __NAMESPACE__ . '\\' . ltrim($seeder, '\\');

        return class_exists($local) || ! class_exists($seeder) ? $local : ltrim($seeder, '\\');
    }

    protected function hasBeenRun(string $class): bool
    {
        // Older logs may hold the short class name.
        return in_array($class, $this->ran, true) || in_array(class_basename($class), $this->ran, true);
    }

    protected function loadRan(): void
    {
        try {
            if (! Schema::hasTable('seeder_log')) {
                // Normally created by its migration; this covers a db:seed run before migrating.
                Schema::create('seeder_log', function (Blueprint $t) {
                    $t->id();
                    $t->string('seeder_name')->unique();
                    $t->timestamp('started_at')->nullable();
                    $t->timestamp('completed_at')->nullable();
                    $t->boolean('success')->default(false);
                    $t->text('error_message')->nullable();
                    $t->timestamps();
                });
            }
            $this->ran = DB::table('seeder_log')->where('success', true)->whereNotNull('completed_at')->pluck('seeder_name')->all();
        } catch (\Throwable $e) {
            Log::warning('Could not read seeder_log: ' . $e->getMessage());
        }
    }

    protected function log(string $class, bool $success, ?string $error = null): void
    {
        try {
            $exists = DB::table('seeder_log')->where('seeder_name', $class)->exists();
            DB::table('seeder_log')->updateOrInsert(['seeder_name' => $class], [
                'started_at' => now(), 'completed_at' => $success ? now() : null, 'success' => $success,
                'error_message' => $error ? mb_substr($error, 0, 5000) : null, 'updated_at' => now(),
            ] + ($exists ? [] : ['created_at' => now()]));
            if ($success) {
                $this->ran[] = $class;
            }
        } catch (\Throwable $e) {
            Log::warning('Could not write seeder_log', ['seeder' => $class, 'error' => $e->getMessage()]); // never stops seeding
        }
    }

    // ---------------------------------------------------------------- statistics and output

    protected function showStats(): void
    {
        $tables = [
            'users' => '👤 Users', 'students' => '👨‍🎓 Students', 'staff_profiles' => '👨‍🏫 Staff', 'faculties' => '🏛️ Faculties',
            'departments' => '🏢 Departments', 'programmes' => '🎓 Programmes', 'courses' => '📘 Courses', 'academic_sessions' => '📅 Sessions',
            'invoices' => '💰 Invoices', 'payments' => '💳 Payments', 'library_items' => '📚 Library titles', 'roles' => '👥 Roles', 'permissions' => '🔐 Permissions',
        ];
        $lines = [];
        foreach ($tables as $table => $label) {
            try {
                if (Schema::hasTable($table) && ($n = DB::table($table)->count()) > 0) {
                    $lines[] = "  {$label}: " . number_format($n);
                }
            } catch (\Throwable) {
                // a table that cannot be read is left out
            }
        }
        $this->info($lines ? implode("\n", $lines) : '  ℹ️  No data yet.');
    }

    protected function section(string $title): void
    {
        $this->info('┌' . str_repeat('─', 77) . '┐');
        $this->info('│ ' . $this->pad($title, 76) . '│');
        $this->info('└' . str_repeat('─', 77) . '┘');
    }

    /** @param  string[]  $lines */
    protected function box(array $lines): void
    {
        $this->info('╔' . str_repeat('═', 77) . '╗');
        foreach ($lines as $l) {
            $this->info('║  ' . $this->pad($l, 75) . '║');
        }
        $this->info('╚' . str_repeat('═', 77) . '╝');
    }

    protected function pad(string $text, int $width): string
    {
        return $text . str_repeat(' ', max(0, $width - mb_strwidth($text)));
    }

    // $this->command is null when the seeder is called from code rather than artisan, so output is optional.
    protected function info(string $s): void
    {
        $this->command?->info($s);
    }

    protected function warn(string $s): void
    {
        $this->command?->warn($s);
    }

    protected function error(string $s): void
    {
        $this->command?->error($s);
    }

    protected function line(string $s): void
    {
        $this->command?->line($s);
    }
}
