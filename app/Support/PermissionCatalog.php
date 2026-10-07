<?php

namespace App\Support;

/**
 * The single source of truth for every permission in the portal.
 *
 * A permission's NAME is what administrators read when they build a role, so each name is a plain
 * "verb + what" sentence ("Edit academic sessions"). Each one also carries an ACTION chip and a
 * DESCRIPTION that says exactly what it allows, and each group carries a short "about" line.
 *
 * Seeded into the database by PermissionTableSeeder; shown by the role editor (PermissionMeta).
 * To add a permission: add it here, use the exact name in the controller/view, run `php artisan db:seed`.
 */
class PermissionCatalog
{
    /** @return array<string, array{about: string, permissions: array<int, array{0: string, 1: string, 2: string}>}> group => [about, [name, action, description]...] */
    public static function groups(): array
    {
        return [
            'Dashboard' => [
                'about' => 'The landing page shown after sign-in.',
                'permissions' => [
                    ['View dashboard', 'View', 'Open the dashboard: the current session and semester, record counts, and the setup checklist. Without this, a user can still use any page they have other permissions for.'],
                ],
            ],

            'Users' => [
                'about' => 'Staff and administrator accounts that can sign in to the portal.',
                'permissions' => [
                    ['View users', 'View', 'See the list of user accounts with their email, phone, roles and status, and open a user\'s profile. Passwords are never shown.'],
                    ['Add users', 'Create', 'Create a new account and give it roles. If no password is typed, a temporary one is generated and shown once.'],
                    ['Edit users', 'Edit', 'Change a user\'s name, email, phone number and roles. Be careful: changing roles changes what that person can do.'],
                    ['Reset user passwords', 'Manage', 'Generate a new temporary password for any user. The old password stops working immediately.'],
                    ['Disable or enable user accounts', 'Manage', 'Block a user from signing in without deleting them, or let them back in. You cannot disable your own account.'],
                    ['Delete users', 'Delete', 'Permanently delete a user account. This cannot be undone. You cannot delete yourself or the last Super Admin.'],
                ],
            ],

            'Roles' => [
                'about' => 'A role is a named bundle of permissions (for example Registrar or Bursar) that is given to users.',
                'permissions' => [
                    ['View roles', 'View', 'See all roles, the permissions each one carries, and the users who hold them.'],
                    ['Create roles', 'Create', 'Create a new role and choose which permissions it carries.'],
                    ['Edit roles', 'Edit', 'Rename a role or change its permissions. The change applies at once to everyone who holds the role.'],
                    ['Assign users to roles', 'Assign', 'Give an existing role to users, which gives them everything that role allows.'],
                    ['Remove users from roles', 'Remove', 'Take a role away from users, which removes the access it gave them.'],
                    ['Delete roles', 'Delete', 'Permanently delete a role. Users who held it lose the access it gave them.'],
                ],
            ],

            'Permissions' => [
                'about' => 'The master list of permissions. Most administrators never need these.',
                'permissions' => [
                    ['View permissions', 'View', 'See the full list of permissions and which roles use each one.'],
                    ['Create permissions', 'Create', 'Add a custom permission name. It has no effect until the portal code checks for it.'],
                    ['Edit permissions', 'Edit', 'Rename a permission. Renaming a built-in permission can lock people out of a page.'],
                    ['Delete permissions', 'Delete', 'Delete a permission. Deleting a built-in one makes the page it protects unreachable for everyone except Super Admin.'],
                ],
            ],

            'Academic sessions' => [
                'about' => 'A session is one academic year, written like 2025/2026. Exactly one session is "current" at a time.',
                'permissions' => [
                    ['View academic sessions', 'View', 'See all academic sessions with their dates, which one is current, and how many semesters each has.'],
                    ['Create academic sessions', 'Create', 'Add a new academic session, optionally with its First and Second semesters.'],
                    ['Edit academic sessions', 'Edit', 'Change a session\'s name or its start and end dates.'],
                    ['Set the current academic session', 'Manage', 'Choose which session the whole portal treats as the present one. Fees, registration and results will follow this choice.'],
                    ['Delete academic sessions', 'Delete', 'Permanently delete a session together with its semesters and all school bills set for it. Do not use once students have been billed.'],
                ],
            ],

            'Semesters' => [
                'about' => 'Each session is divided into semesters (First, Second ...). Exactly one semester is "current" at a time.',
                'permissions' => [
                    ['View semesters', 'View', 'See the semesters of every session with their dates and which one is current.'],
                    ['Create semesters', 'Create', 'Add a semester (such as a Second or Summer semester) to a session.'],
                    ['Edit semesters', 'Edit', 'Change a semester\'s name, order or dates.'],
                    ['Set the current semester', 'Manage', 'Choose which semester is running now. This also makes that semester\'s session the current session.'],
                    ['Delete semesters', 'Delete', 'Permanently delete a semester.'],
                ],
            ],

            'Faculties' => [
                'about' => 'The top level of the academic structure (a Faculty, School or College). Departments belong to a faculty.',
                'permissions' => [
                    ['View faculties', 'View', 'See all faculties with their codes, deans and number of departments.'],
                    ['Create faculties', 'Create', 'Add a new faculty (a major division of the university, e.g. Faculty of Science).'],
                    ['Edit faculties', 'Edit', 'Change a faculty\'s name, code, dean, description or active status.'],
                    ['Delete faculties', 'Delete', 'Delete a faculty. This is refused while it still has departments.'],
                ],
            ],

            'Departments' => [
                'about' => 'A department sits inside a faculty and owns programmes and courses.',
                'permissions' => [
                    ['View departments', 'View', 'See all departments with their faculty, head of department, programmes and courses.'],
                    ['Create departments', 'Create', 'Add a new department to a faculty.'],
                    ['Edit departments', 'Edit', 'Change a department\'s name, code, faculty, head of department or active status.'],
                    ['Delete departments', 'Delete', 'Delete a department. This is refused while it still has programmes or courses.'],
                ],
            ],

            'Programmes' => [
                'about' => 'A programme is a degree or diploma a department awards, such as B.Sc. Computer Science. Its length decides its levels.',
                'permissions' => [
                    ['View programmes', 'View', 'See all programmes with their degree type, duration and credit-unit limits.'],
                    ['Create programmes', 'Create', 'Add a new programme, including its duration and the minimum and maximum credit units per semester.'],
                    ['Edit programmes', 'Edit', 'Change a programme\'s details, duration or credit-unit limits. Shortening the duration hides the higher levels.'],
                    ['Delete programmes', 'Delete', 'Delete a programme. This is refused while it has a curriculum or school bills.'],
                ],
            ],

            'Courses' => [
                'about' => 'The catalogue of courses (CSC 101, MTH 201 ...). Each has a code, title and credit units.',
                'permissions' => [
                    ['View courses', 'View', 'See the course catalogue with each course\'s code, title, department, level and credit units.'],
                    ['Create courses', 'Create', 'Add a new course to the catalogue.'],
                    ['Edit courses', 'Edit', 'Change a course\'s code, title, credit units, level or active status. Credit unit changes affect every programme that uses the course.'],
                    ['Delete courses', 'Delete', 'Delete a course. This is refused while any programme\'s curriculum uses it.'],
                ],
            ],

            'Programme curriculum' => [
                'about' => 'Which courses each programme takes at each level and semester, and whether each is core, required or elective.',
                'permissions' => [
                    ['View programme curriculum', 'View', 'See each programme\'s courses by level and semester, with credit-unit totals checked against the programme\'s limits.'],
                    ['Edit programme curriculum', 'Manage', 'Add courses to a programme, move them to another level or semester, change core/required/elective status, or remove them.'],
                ],
            ],

            'Bill items' => [
                'about' => 'The kinds of charge a student can be billed for: tuition, acceptance fee, hostel, library and so on. No amounts here.',
                'permissions' => [
                    ['View bill items', 'View', 'See the list of bill items and whether each is compulsory and active.'],
                    ['Create bill items', 'Create', 'Add a new kind of charge (e.g. tuition, acceptance fee) that can then be priced in school bills.'],
                    ['Edit bill items', 'Edit', 'Rename a bill item or change whether it is compulsory or active.'],
                    ['Delete bill items', 'Delete', 'Delete a bill item. This is refused while any school bill uses it.'],
                ],
            ],

            'School bills' => [
                'about' => 'How much each bill item costs for a programme and level in a session, for all, fresh or returning students.',
                'permissions' => [
                    ['View school bills', 'View', 'See the amounts charged per session, programme and level, with totals.'],
                    ['Create school bills', 'Create', 'Set the amount of a bill item for a programme, level and session.'],
                    ['Edit school bills', 'Edit', 'Change an amount, who it applies to, or switch a bill off. Money-related: changes alter what students are charged.'],
                    ['Copy school bills to another session', 'Manage', 'Copy a whole session\'s bills into another session, optionally raising or lowering every amount by a percentage. Existing bills are never overwritten.'],
                    ['Delete school bills', 'Delete', 'Permanently delete a bill amount.'],
                ],
            ],

            'Applicants' => [
                'about' => 'People who have applied but are not students yet. Admitting an applicant creates their student record, matric number and login.',
                'permissions' => [
                    ['View applicants', 'View', 'See the list of applicants with their programme, entry level and admission status, and search or filter it.'],
                    ['Create applicants', 'Create', 'Record a new applicant with their personal details, guardian, programme and entry session.'],
                    ['Edit applicants', 'Edit', 'Correct an applicant\'s details. Not possible once the applicant has been admitted.'],
                    ['Admit applicants', 'Manage', 'Admit an applicant as a student. This generates a matric number, creates a login account with a temporary password, and cannot be undone. Also lets you admit a previously rejected applicant.'],
                    ['Reject applicants', 'Manage', 'Mark a pending application as rejected. The record is kept and the person can still be admitted later.'],
                    ['Delete applicants', 'Delete', 'Permanently delete an applicant who has not been admitted.'],
                ],
            ],

            'Students' => [
                'about' => 'People who have been admitted and hold a matric number.',
                'permissions' => [
                    ['View students', 'View', 'See the list of students and open a student\'s full profile: personal details, programme, level, guardian and status.'],
                    ['Edit students', 'Edit', 'Correct a student\'s personal details, guardian, login email and current level, and upload their passport photo.'],
                    ['Change student status', 'Manage', 'Mark a student active, deferred, suspended, withdrawn or graduated. Suspended and withdrawn students are blocked from signing in.'],
                    ['Reset student passwords', 'Manage', 'Generate a new temporary password for a student. The old password stops working at once.'],
                ],
            ],

            'Matric number format' => [
                'about' => 'The pattern used to generate matric numbers, for example 2025/BSC-CSC/0001.',
                'permissions' => [
                    ['View matric number format', 'View', 'See the current matric number pattern and an example of the next number.'],
                    ['Set the matric number format', 'Set', 'Change the pattern used for students admitted from now on. Existing matric numbers never change.'],
                ],
            ],
        ];
    }

    /** name => ['group', 'action', 'description', 'position'] */
    public static function all(): array
    {
        static $flat = null;

        if ($flat === null) {
            $flat = [];
            $i = 0;
            foreach (self::groups() as $group => $data) {
                foreach ($data['permissions'] as [$name, $action, $description]) {
                    $flat[$name] = ['group' => $group, 'action' => $action, 'description' => $description, 'position' => $i++];
                }
            }
        }

        return $flat;
    }

    public static function find(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    public static function about(string $group): ?string
    {
        return self::groups()[$group]['about'] ?? null;
    }

    /**
     * Old permission name => new name. The seeder moves existing role/user assignments across,
     * so renaming never silently removes anyone's access.
     */
    public static function renamed(): array
    {
        return [
            'dashboard'          => 'View dashboard',
            'View user'          => 'View users',
            'Create user'        => 'Add users',
            'Update user'        => 'Edit users',
            'Delete user'        => 'Delete users',
            'View role'          => 'View roles',
            'Create role'        => 'Create roles',
            'Update role'        => 'Edit roles',
            'Delete role'        => 'Delete roles',
            'Add user-role'      => 'Assign users to roles',
            'Update user-role'   => 'Assign users to roles',
            'Remove user-role'   => 'Remove users from roles',
            'View permission'    => 'View permissions',
            'Create permission'  => 'Create permissions',
            'Update permission'  => 'Edit permissions',
            'Delete permission'  => 'Delete permissions',
            'View session'       => 'View academic sessions',
            'Create session'     => 'Create academic sessions',
            'Update session'     => 'Edit academic sessions',
            'Delete session'     => 'Delete academic sessions',
            'Set current session' => 'Set the current academic session',
            'View semester'      => 'View semesters',
            'Create semester'    => 'Create semesters',
            'Update semester'    => 'Edit semesters',
            'Delete semester'    => 'Delete semesters',
            'Set current semester' => 'Set the current semester',
            'View faculty'       => 'View faculties',
            'Create faculty'     => 'Create faculties',
            'Update faculty'     => 'Edit faculties',
            'Delete faculty'     => 'Delete faculties',
            'View department'    => 'View departments',
            'Create department'  => 'Create departments',
            'Update department'  => 'Edit departments',
            'Delete department'  => 'Delete departments',
            'View programme'     => 'View programmes',
            'Create programme'   => 'Create programmes',
            'Update programme'   => 'Edit programmes',
            'Delete programme'   => 'Delete programmes',
            'View course'        => 'View courses',
            'Create course'      => 'Create courses',
            'Update course'      => 'Edit courses',
            'Delete course'      => 'Delete courses',
            'View curriculum'    => 'View programme curriculum',
            'Update curriculum'  => 'Edit programme curriculum',
            'View bill-item'     => 'View bill items',
            'Create bill-item'   => 'Create bill items',
            'Update bill-item'   => 'Edit bill items',
            'Delete bill-item'   => 'Delete bill items',
            'View bill'          => 'View school bills',
            'Create bill'        => 'Create school bills',
            'Update bill'        => 'Edit school bills',
            'Delete bill'        => 'Delete school bills',
            'Copy bills'         => 'Copy school bills to another session',
        ];
    }

    /** Anyone who held the old permission also receives these (it used to cover them). */
    public static function alsoGranted(): array
    {
        return [
            'Update user' => ['Reset user passwords', 'Disable or enable user accounts'],
        ];
    }
}
