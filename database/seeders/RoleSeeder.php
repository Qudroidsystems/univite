<?php

namespace Database\Seeders;

use App\Models\CourseAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Access\LecturerRole;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The university's usual offices as roles, each with the permissions it needs. Nothing in the portal checks these
 * names: pages check permissions, so a university can rename a role, change what it may do on the Roles page, or
 * make its own. Heads get their role for their own department, faculty or unit only (Scoped Roles & Heads), so the
 * head roles reach no further than what the head runs.
 *
 * Safe to re-run. A role is created when missing; each default permission is given once (remembered in the
 * "roles.seeded" setting), so one an administrator later takes away is not given back, while a permission added to
 * this list in an update reaches existing installs.
 */
class RoleSeeder extends Seeder
{
    /** role => [badge, permission groups from the catalog, single permissions ("!name" leaves one of the groups' out)] */
    public const ROLES = [
        'Head of Department' => ['badge bg-secondary', [], [
            'View students', 'View student results', 'View course registrations', 'Approve course registrations', 'View leave applications', 'View staff records',
            'Approve results', 'Edit scores while approving', 'Waive carryover courses', 'Define courses for lecturers', 'Compute results', 'Edit department academic rules', 'Enter results for assigned courses',
        ]],
        'Dean' => ['badge bg-secondary', [], [
            'View students', 'View student results', 'View course registrations', 'View leave applications', 'View staff records', 'Approve results', 'Edit scores while approving', 'Edit department academic rules',
        ]],
        'Head of Unit' => ['badge bg-secondary', [], ['View leave applications', 'View staff records']],
        'Lecturer' => ['badge bg-info', [], ['Enter results for assigned courses']],
        'Senate' => ['badge bg-dark', [], ['View result sheets', 'View student results', 'Approve results', 'Edit scores while approving', 'Publish results']],
        'Registrar' => ['badge bg-primary', ['Applicants', 'Application settings', 'Students', 'Clearance', 'Matric numbers', 'Course registrations', 'Student results', 'Graduation lists', 'Academic sessions', 'Staff leave'], ['!Approve leave for any staff']],
        'Bursar' => ['badge bg-primary', ['Bill items', 'School bills', 'Invoices & payments'], []],
        // Leave goes through its approval route; acting on any step out of turn is left for an administrator to give.
        'HR Officer' => ['badge bg-primary', ['Staff records', 'Staff leave', 'Units', 'Payroll'], ['!Approve leave for any staff']],
        'Exams Officer' => ['badge bg-primary', ['Timetables', 'Examination seating', 'Venues'], ['View result sheets', 'Enter results for any course']],
        'Librarian' => ['badge bg-primary', ['Library'], []],
        'Hostel Officer' => ['badge bg-primary', ['Accommodation'], []],
        'Medical Officer' => ['badge bg-primary', ['Health & clinic'], []],
    ];

    public function run(LecturerRole $lecturers, PermissionRegistrar $registrar): void
    {
        $existing = Permission::where('guard_name', 'web')->pluck('name')->flip();
        $seeded = json_decode((string) Setting::get('roles.seeded', '[]'), true) ?: [];

        foreach (self::ROLES as $name => [$badge, $groups, $permissions]) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['badge' => $badge]);
            $wanted = collect($groups)->flatMap(fn ($g) => collect(PermissionCatalog::groups()[$g]['permissions'] ?? [])->pluck(0))
                ->merge(collect($permissions)->reject(fn ($p) => str_starts_with($p, '!')))
                ->diff(collect($permissions)->filter(fn ($p) => str_starts_with($p, '!'))->map(fn ($p) => substr($p, 1)))
                ->unique()->filter(fn ($p) => $existing->has($p));
            $new = $wanted->reject(fn ($p) => in_array($p, $seeded[$name] ?? [], true))->values();
            if ($new->isNotEmpty()) {
                $role->givePermissionTo($new->all());
                $seeded[$name] = array_values(array_unique([...($seeded[$name] ?? []), ...$new->all()]));
            }
        }
        Setting::put('roles.seeded', json_encode($seeded));
        $registrar->forgetCachedPermissions();

        // Everyone a course is defined for can enter its scores.
        User::whereIn('id', CourseAssignment::select('user_id'))->get()->each(fn (User $u) => $lecturers->ensure($u));
    }
}
