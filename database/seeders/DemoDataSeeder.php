<?php

namespace Database\Seeders;

use App\Models\CourseAssignment;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Semester;
use App\Models\SemesterType;
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
        DemoResultApprovalSeeder::class,
        DemoResultKeySeeder::class,
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
        $heads = Department::whereNotNull('hod_id')->pluck('hod_id')->merge(Faculty::whereNotNull('dean_id')->pluck('dean_id'));
        $row = fn (string $role, ?User $user, string $note = '') => $user ? [$role, $user->email, $note ?: (string) $user->username] : null;

        $rows = [];
        $rows[] = $row('Registrar', User::role('Registrar')->orderBy('id')->first(), 'signs the Senate step; publishes results');
        foreach (['Bursar', 'Exams Officer', 'HR Officer', 'Librarian', 'Hostel Officer', 'Medical Officer'] as $role) {
            $rows[] = $row($role, User::role($role)->orderBy('id')->first());
        }
        $rows[] = $row('Dean', User::find(Faculty::whereNotNull('dean_id')->orderBy('id')->value('dean_id')), 'approves results for the faculty');
        $rows[] = $row('Head of Department', User::find(Department::whereNotNull('hod_id')->orderBy('id')->value('hod_id')), 'approves results for the department');

        // A lecturer who is not a head, teaching the largest class this semester.
        $semester = Semester::current();
        $type = $semester ? SemesterType::where('sort_order', $semester->sort_order)->first() : null;
        $assignment = CourseAssignment::with('course', 'user')
            ->where('academic_session_id', $semester?->academic_session_id)->where('semester_type_id', $type?->id)
            ->whereNotIn('user_id', $heads)->get()
            ->sortByDesc(fn ($a) => DB::table('course_registrations')->where('course_id', $a->course_id)->count())
            ->first();
        $rows[] = $row('Lecturer', $assignment?->user, $assignment ? 'teaches ' . $assignment->course->code . ': Results > open the course' : '');
        $rows[] = $row('Student', User::role('Student')->orderBy('id')->first());

        $this->command?->table(['Role', 'Email', 'Username / note'], array_values(array_filter($rows)));
        $this->command?->line('  Every sample account uses the password "' . DemoStaffSeeder::PASSWORD . '".');
    }
}
