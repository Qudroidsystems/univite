<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\SalaryStructure;
use App\Models\SemesterType;
use App\Models\StaffProfile;
use App\Models\Unit;
use App\Models\User;
use App\Services\Access\HeadRoles;
use App\Support\PermissionCatalog;
use Database\Seeders\Demo\DemoPeople;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sample data, part 2: four lecturers in every department (the first one is head of department), a dean for every
 * faculty, the registry, bursary, HR, library, hostel and clinic officers with their roles, units with heads, and
 * every course of the current session assigned to a lecturer. Every account's password is "password".
 */
class DemoStaffSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** Hashed once and reused: hashing the same password for hundreds of accounts is most of the seeding time. */
    public static function passwordHash(): string
    {
        static $hash;

        return $hash ??= \Illuminate\Support\Facades\Hash::make(self::PASSWORD);
    }

    /** Role => permission groups from the catalog it gets. */
    private const ROLES = [
        'Registrar' => ['Applicants', 'Application settings', 'Students', 'Clearance', 'Matric numbers', 'Course registrations', 'Student results', 'Graduation lists', 'Academic sessions', 'Staff leave'],
        'Bursar' => ['Bill items', 'School bills', 'Invoices & payments'],
        'HR Officer' => ['Staff records', 'Staff leave', 'Units', 'Payroll'],
        'Librarian' => ['Library'],
        'Hostel Officer' => ['Accommodation'],
        'Medical Officer' => ['Health & clinic'],
        'Exams Officer' => ['Timetables', 'Examination seating', 'Venues'],
        'Lecturer' => [],
    ];

    /** [title, unit, designation, role, grade on CONTISS] */
    private const OFFICERS = [
        ['Mr', 'Registry', 'Registrar', 'Registrar', 15], ['Mrs', 'Registry', 'Deputy Registrar (Academic)', 'Registrar', 14],
        ['Mr', 'Bursary', 'Bursar', 'Bursar', 15], ['Mrs', 'Bursary', 'Principal Accountant', 'Bursar', 12],
        ['Mrs', 'Human Resources', 'Deputy Registrar (Establishments)', 'HR Officer', 14],
        ['Dr', 'Library', 'University Librarian', 'Librarian', 15], ['Mr', 'Library', 'Librarian II', 'Librarian', 9],
        ['Mr', 'Student Affairs', 'Hall Warden', 'Hostel Officer', 11], ['Dr', 'Health Centre', 'Chief Medical Officer', 'Medical Officer', 14],
        ['Mr', 'Academic Planning', 'Examinations Officer', 'Exams Officer', 12], ['Mr', 'ICT Centre', 'Systems Administrator', null, 10],
    ];

    private const RANKS = [['Prof', 'Professor', 7], ['Dr', 'Senior Lecturer', 5], ['Dr', 'Lecturer I', 4], ['Mr', 'Lecturer II', 3], ['Mrs', 'Assistant Lecturer', 2]];

    public function run(HeadRoles $heads, PermissionRegistrar $registrar): void
    {
        $people = new DemoPeople(2101);
        $n = 0;
        $conuass = SalaryStructure::where('code', 'CONUASS')->first();
        $contiss = SalaryStructure::where('code', 'CONTISS')->first();

        foreach (self::ROLES as $name => $groups) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['badge' => 'badge bg-secondary']);
            $names = collect($groups)->flatMap(fn ($g) => collect(PermissionCatalog::groups()[$g]['permissions'] ?? [])->pluck(0))
                ->merge($name === 'Lecturer' ? ['View result sheets', 'View course assignments'] : [])->all();
            $role->givePermissionTo(Permission::whereIn('name', $names)->get());
        }
        $registrar->forgetCachedPermissions();

        $make = function (string $title, string $designation, string $category, ?int $deptId, ?int $unitId, ?SalaryStructure $structure, int $grade, ?string $gender = null) use ($people, &$n) {
            $n++;
            $p = $people->person($title === 'Mrs' ? 'female' : ($title === 'Mr' ? 'male' : $gender));
            [$state] = $people->origin();
            $user = User::updateOrCreate(['email' => $people->slug($p['first'], $p['last'], $n) . '@staff.univite.test'], [
                'name' => "{$p['first']} {$p['last']}", 'password' => self::passwordHash(), 'gender' => $p['gender'], 'phone_number' => $people->phone(),
                'date_of_birth' => $people->birthDate($people->int(32, 62)),
            ]);
            StaffProfile::updateOrCreate(['user_id' => $user->id], [
                'staff_number' => sprintf('SP/%04d', 1000 + $n), 'department_id' => $deptId, 'unit_id' => $unitId, 'designation' => $designation, 'title' => strtolower($title),
                'category' => $category, 'appointment_type' => 'permanent', 'grade_level' => $grade, 'salary_structure_id' => $structure?->id, 'step' => $people->int(1, 9),
                'employment_status' => 'active', 'first_appointment_date' => now()->subYears($people->int(2, 25))->toDateString(), 'tax_state' => $state,
                'bank_name' => $people->pick(['First Bank', 'GTBank', 'Access Bank', 'UBA', 'Zenith Bank', 'Wema Bank']),
                'bank_account_number' => str_pad((string) $people->int(0, 999999999), 10, '0', STR_PAD_LEFT), 'bank_account_name' => $user->name,
                'pfa_code' => $people->pick(['STANBIC', 'ARM', 'PREMIUM', 'LEADWAY']), 'rsa_pin' => 'PEN' . str_pad((string) $people->int(0, 999999999), 12, '0', STR_PAD_LEFT),
            ]);

            return $user;
        };

        // Units, each with a head.
        $units = [];
        foreach (array_unique(array_column(self::OFFICERS, 1)) as $unitName) {
            $units[$unitName] = Unit::firstOrCreate(['name' => $unitName], ['code' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $unitName), 0, 4)), 'is_active' => true]);
        }
        foreach (self::OFFICERS as [$title, $unitName, $designation, $role, $grade]) {
            $user = $make($title, $designation, 'senior_non_teaching', null, $units[$unitName]->id, $contiss, $grade);
            $role && $user->assignRole($role);
            if (! $units[$unitName]->head_id) {
                $units[$unitName]->update(['head_id' => $user->id]);
            }
        }

        // Lecturers: four per department, the first heads it. Each faculty also gets a dean.
        $lecturers = [];
        foreach (Department::with('faculty')->orderBy('id')->get() as $dept) {
            foreach (self::RANKS as $i => [$title, $designation, $grade]) {
                if ($i === 4 && $people->chance(50)) {
                    continue;
                }
                $user = $make($title, $designation, 'academic', $dept->id, null, $conuass, $grade);
                $user->assignRole('Lecturer');
                $lecturers[$dept->id][] = $user;
                if ($i === 0) {
                    $dept->update(['hod_id' => $user->id]);
                }
            }
        }
        foreach (Faculty::orderBy('id')->get() as $faculty) {
            $home = $faculty->departments()->orderBy('id')->first();
            $dean = $make('Prof', 'Professor and Dean', 'academic', $home?->id, null, $conuass, 7);
            $dean->assignRole('Lecturer');
            $faculty->update(['dean_id' => $dean->id]);
        }
        $heads->syncAll();

        // This session's courses: each one to a lecturer of its department, in turn.
        $session = AcademicSession::current();
        $types = SemesterType::orderBy('sort_order')->get()->keyBy('sort_order');
        $turn = [];
        foreach (Course::with('programmeCourses')->orderBy('code')->get() as $course) {
            $pool = $lecturers[$course->department_id] ?? [];
            if (! $pool || ! $session) {
                continue;
            }
            $order = $course->programmeCourses->first()?->semester_order ?? 1;
            $turn[$course->department_id] = (($turn[$course->department_id] ?? -1) + 1) % count($pool);
            CourseAssignment::firstOrCreate(['user_id' => $pool[$turn[$course->department_id]]->id, 'course_id' => $course->id,
                'academic_session_id' => $session->id, 'semester_type_id' => $types[$order]->id]);
        }
    }
}
