<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A whole sample university for a development machine: academic structure, staff, students, registrations and results,
 * fees and payments, venues, hostels, the library and teaching-practice schools. The parts depend on each other, so they
 * run together in one transaction: if one fails, nothing is left half-seeded. DatabaseSeeder runs it only in the local
 * or development environment; it can also be run on its own with  php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public const PARTS = [
        DemoAcademicSeeder::class,
        DemoStaffSeeder::class,
        DemoStudentSeeder::class,
        DemoAcademicRecordSeeder::class,
        DemoAssessmentSeeder::class,
        DemoFeesSeeder::class,
        DemoCampusSeeder::class,
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Sample data is never seeded on a live server.');

            return;
        }

        DB::transaction(fn () => $this->call(self::PARTS));
        $this->showLogins();
    }

    /** One sample account per role, so whoever ran the seeder can sign in straight away. */
    private function showLogins(): void
    {
        $rows = [];
        foreach (['Registrar', 'Bursar', 'Exams Officer', 'HR Officer', 'Librarian', 'Hostel Officer', 'Medical Officer', 'Lecturer', 'Student'] as $role) {
            $user = User::role($role)->orderBy('id')->first();
            if ($user) {
                $rows[] = [$role, $user->email, $user->username ?? ''];
            }
        }
        foreach (['Dean' => Faculty::whereNotNull('dean_id')->orderBy('id')->value('dean_id'), 'Head of Department' => Department::whereNotNull('hod_id')->orderBy('id')->value('hod_id')] as $role => $id) {
            if ($user = User::find($id)) {
                array_splice($rows, count($rows) - 2, 0, [[$role, $user->email, $user->username ?? '']]);
            }
        }
        $this->command?->table(['Role', 'Email', 'Username'], $rows);
        $this->command?->line('  Every sample account uses the password "' . DemoStaffSeeder::PASSWORD . '".');
    }
}
