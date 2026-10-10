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

            'Institution profile' => [
                'about' => 'The institution\'s own details: name, address, contacts, principal officers, logo, signature, stamp and colours.',
                'permissions' => [
                    ['View institution profile', 'View', 'See the institution\'s name, address, contacts, principal officers and branding as set in the portal.'],
                    ['Edit institution profile', 'Edit', 'Change the institution\'s name, address, contacts, officers, logo, signature, stamp and colours. The change shows everywhere at once, including printed documents.'],
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

            'Session lifecycle' => [
                'about' => 'Sessions move through Draft, Setup, Active, Closing, Closed and Archived. Every change is checked first and recorded with a reason.',
                'permissions' => [
                    ['Change session status', 'Manage', 'Move a session to the next stage (activate, start closing, close, reopen or archive it). A session is checked before it goes live and a reason is recorded.'],
                    ['Copy session setup', 'Manage', 'Copy a session\'s setup (registration windows, school bills, course lecturers) into another session after a preview. No student data, scores or payments are ever copied.'],
                    ['Undo session copies', 'Manage', 'Undo a session copy. Only what nothing has used since is removed; anything already in use is kept and reported.'],
                    ['View session rules', 'View', 'See the rules a university can choose for sessions, such as late registration and whether several sessions can be active together.'],
                    ['Edit session rules', 'Edit', 'Change the session rules, such as late registration days and whether closed sessions can be reopened.'],
                ],
            ],

            'Operation history' => [
                'about' => 'A permanent log of bulk and sensitive actions (session copies, matric batches, published results). Entries are never edited or deleted.',
                'permissions' => [
                    ['View operation history', 'View', 'Search the log of bulk and sensitive actions by session, module, person, date or operation ID, and see exactly what each one changed.'],
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

            'Study categories' => [
                'about' => 'The kinds of programme the university runs (Degree, NCE, Diploma, Postgraduate ...). A category sets how levels are named, for example "100 Level" or "NCE 1".',
                'permissions' => [
                    ['View study categories', 'View', 'See every study category with its duration and how its levels are labelled.'],
                    ['Create study categories', 'Create', 'Add a study category and choose how its levels are written.'],
                    ['Edit study categories', 'Edit', 'Rename a category or change its level format. Existing programmes pick up the new wording straight away.'],
                    ['Delete study categories', 'Delete', 'Delete a study category. This is refused while any programme belongs to it.'],
                ],
            ],

            'Semester types' => [
                'about' => 'The semesters the university teaches in (First and Second, or Harmattan, Rain and Third). Each new session gets one semester per active type.',
                'permissions' => [
                    ['View semester types', 'View', 'See the semester types and the order they run in.'],
                    ['Create semester types', 'Create', 'Add a semester type such as a Third or Summer semester.'],
                    ['Edit semester types', 'Edit', 'Rename a semester type or switch it on or off. Renaming changes the name shown everywhere.'],
                    ['Delete semester types', 'Delete', 'Delete a semester type. This is refused while any curriculum or semester uses it.'],
                ],
            ],

            'Custom fields' => [
                'about' => 'Extra questions added to the applicant, student and staff forms: text, numbers, dates, choices, yes/no and file uploads.',
                'permissions' => [
                    ['View custom fields', 'View', 'See the extra form fields that have been added and where they appear.'],
                    ['Create custom fields', 'Create', 'Add a new question to the applicant, student or staff form.'],
                    ['Edit custom fields', 'Edit', 'Change a custom field: its label, choices, whether it is required, or hide it.'],
                    ['Delete custom fields', 'Delete', 'Delete a custom field. Every answer and uploaded file for it is deleted too.'],
                ],
            ],

            'Grade bands' => [
                'about' => 'The grading scale: which score earns which letter and grade points, and which grades are a pass.',
                'permissions' => [
                    ['View grade bands', 'View', 'See the grading scale: score ranges, letters, grade points and pass marks.'],
                    ['Create grade bands', 'Create', 'Add a grade to the scale, for example a new letter or a different pass mark.'],
                    ['Edit grade bands', 'Edit', 'Change the score range, points or pass status of a grade. Results that are already approved are not regraded.'],
                    ['Delete grade bands', 'Delete', 'Remove a grade from the scale. Refused while any result uses it.'],
                ],
            ],

            'Degree classes' => [
                'about' => 'The class of degree a graduating student earns from their CGPA (First Class, Second Class Upper ...).',
                'permissions' => [
                    ['View degree classes', 'View', 'See the classes of degree and the CGPA each one needs.'],
                    ['Create degree classes', 'Create', 'Add a class of degree with its minimum CGPA.'],
                    ['Edit degree classes', 'Edit', 'Rename a class of degree or change its minimum CGPA.'],
                    ['Delete degree classes', 'Delete', 'Remove a class of degree from the list used to classify graduates.'],
                ],
            ],

            'Course registrations' => [
                'about' => 'Students choose their courses each semester. A course adviser or HOD reviews the form before it counts.',
                'permissions' => [
                    ['View course registrations', 'View', 'See students\' course registration forms and the courses on each one.'],
                    ['Approve course registrations', 'Manage', 'Approve a submitted registration form, return it to the student with a reason, or reopen an approved form so the student can change it.'],
                ],
            ],

            'Registration settings' => [
                'about' => 'The rules for course registration: unit limits, fee check, approval, carry-overs and prerequisites.',
                'permissions' => [
                    ['View registration settings', 'View', 'See the course registration rules.'],
                    ['Edit registration settings', 'Edit', 'Change the unit limits, fee check, approval step and other registration rules.'],
                ],
            ],

            'Course assignments' => [
                'about' => 'Which lecturer teaches which course each semester. Assigned lecturers can enter scores for their courses.',
                'permissions' => [
                    ['View course assignments', 'View', 'See which lecturer is assigned to which course in each session and semester.'],
                    ['Create course assignments', 'Create', 'Assign a lecturer to a course for a session and semester.'],
                    ['Edit course assignments', 'Edit', 'Change a lecturer assignment, for example to a different course or semester.'],
                    ['Delete course assignments', 'Delete', 'Remove a lecturer assignment so they can no longer enter scores for that course.'],
                ],
            ],

            'Result stages' => [
                'about' => 'The approval steps a score sheet passes through after the lecturer submits it, such as Head of Department then Dean.',
                'permissions' => [
                    ['View result stages', 'View', 'See the result approval steps and which role signs each one.'],
                    ['Create result stages', 'Create', 'Add an approval step to the result chain, for example a Senate step.'],
                    ['Edit result stages', 'Edit', 'Rename an approval step, change its order or role, or switch it off.'],
                    ['Delete result stages', 'Delete', 'Remove an approval step. Refused while score sheets are waiting at it.'],
                ],
            ],

            'Result sheets' => [
                'about' => 'Course score sheets: lecturers enter or upload CA and exam scores, then the sheet is approved step by step and published to students.',
                'permissions' => [
                    ['View result sheets', 'View', 'See every course score sheet and where it is in the approval chain, even for courses you do not teach.'],
                    ['Enter results for any course', 'Manage', 'Enter, upload and submit scores for any course, not only the ones you are assigned to. Give this to the exams officer.'],
                    ['Approve results', 'Manage', 'Approve or return a score sheet waiting at your approval step (the role set on each step decides which steps are yours).'],
                    ['Approve results for any stage', 'Manage', 'Approve or return a score sheet at any step of the chain, whatever role the step asks for.'],
                    ['Amend published results', 'Manage', 'Correct a single student\'s score on a published sheet. A reason is required and the old and new scores are recorded on the sheet\'s history.'],
                    ['Publish results', 'Manage', 'Publish an approved score sheet so students can see their results, or send an approved sheet back for correction.'],
                ],
            ],

            'Student results' => [
                'about' => 'Published results, GPA and CGPA for individual students.',
                'permissions' => [
                    ['View student results', 'View', 'Open any student\'s published results and print their transcript with semester GPA, CGPA and class.'],
                ],
            ],

            'Result settings' => [
                'about' => 'How a course\'s marks are split between continuous assessment and exam.',
                'permissions' => [
                    ['View result settings', 'View', 'See the CA and exam mark split used for score entry.'],
                    ['Edit result settings', 'Edit', 'Change the CA and exam mark split (it must add up to 100), how long students may query a result, and the graduation rules.'],
                ],
            ],

            'Academic rules' => [
                'about' => 'Credit load, carry-over, CGPA, probation and graduation rules. The university sets defaults; faculties, departments and programmes can override them, and the credit load can differ by level and semester.',
                'permissions' => [
                    ['View academic rules', 'View', 'See the academic rules of the university and of every faculty, department and programme, and where each value comes from.'],
                    ['Edit academic rules', 'Edit', 'Change the academic rules anywhere, including the university defaults, and choose who administers each department.'],
                    ['Edit department academic rules', 'Edit', 'Change the academic rules of your own department and its programmes (as head of department or a department administrator), or of your faculty and everything in it (as dean).'],
                ],
            ],

            'Result complaints' => [
                'about' => 'Queries students raise about a published result, and the answers they get.',
                'permissions' => [
                    ['View result complaints', 'View', 'See every query students have raised about their published results, with the answers given.'],
                    ['Respond to result complaints', 'Manage', 'Answer a student\'s result query as upheld or not upheld. With "Amend published results" as well, the score can be corrected in the same step.'],
                ],
            ],

            'Graduation lists' => [
                'about' => 'The final-year run: each final-year student is checked against the graduation rules, then the list is approved and the eligible students graduate.',
                'permissions' => [
                    ['View graduation lists', 'View', 'See graduation lists for each programme: who is eligible, CGPA and class, and why others cannot graduate yet.'],
                    ['Generate graduation lists', 'Generate', 'Run or re-run the graduation check for a programme, and hold individual students back with a reason.'],
                    ['Approve graduation lists', 'Approve', 'Approve a graduation list. Every eligible student on it is marked Graduated with their final CGPA and class. This cannot be undone from the portal.'],
                ],
            ],

            'Student statuses' => [
                'about' => 'The states a student can be in (Active, Deferred, Suspended ...). Each status controls whether the student can sign in, register for courses, pay fees and counts as enrolled.',
                'permissions' => [
                    ['View student statuses', 'View', 'See every student status and what it allows the student to do.'],
                    ['Create student statuses', 'Create', 'Add a student status, for example Probation, and choose what it allows.'],
                    ['Edit student statuses', 'Edit', 'Rename a status or change what it allows. Changing sign-in access updates every student who has that status.'],
                    ['Delete student statuses', 'Delete', 'Delete a student status. This is refused for the default status or any status a student currently has.'],
                ],
            ],

            'Entry modes' => [
                'about' => 'The ways a student can enter the university (UTME, Direct Entry, Transfer ...). Each sets the entry level and whether JAMB scores or a prior result are needed.',
                'permissions' => [
                    ['View entry modes', 'View', 'See the entry modes with their entry level and requirements.'],
                    ['Create entry modes', 'Create', 'Add an entry mode and set its entry level and requirements.'],
                    ['Edit entry modes', 'Edit', 'Change an entry mode\'s name, entry level, requirements or whether it is open for online application.'],
                    ['Delete entry modes', 'Delete', 'Delete an entry mode. This is refused while any applicant uses it.'],
                ],
            ],

            'Reference lists' => [
                'about' => 'The pick-lists used across forms: titles, gender, religion, blood group, states and so on. Edit them so forms match this university.',
                'permissions' => [
                    ['View reference lists', 'View', 'See every pick-list and its options.'],
                    ['Create reference lists', 'Create', 'Add a new option to one of the pick-lists, such as a new title or religion.'],
                    ['Edit reference lists', 'Edit', 'Rename an option or switch it on or off. The stored code stays the same so old records keep working.'],
                    ['Delete reference lists', 'Delete', 'Delete an option you added. Built-in options cannot be deleted, only switched off.'],
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
                    ['View applicants', 'View', 'See the list of applicants (including those who applied online), open an application to read their details and uploaded documents, and search or filter the list.'],
                    ['Create applicants', 'Create', 'Record a new applicant with their personal details, guardian, programme and entry session.'],
                    ['Edit applicants', 'Edit', 'Correct an applicant\'s details. Not possible once the applicant has been admitted.'],
                    ['Admit applicants', 'Manage', 'Admit a submitted applicant as a student, one at a time or a whole batch at once. This generates a matric number and a login account, and cannot be undone. Also lets you admit a previously rejected applicant.'],
                    ['Reject applicants', 'Manage', 'Mark a pending application as rejected. The record is kept and the person can still be admitted later.'],
                    ['Delete applicants', 'Delete', 'Permanently delete an applicant who has not been admitted.'],
                ],
            ],

            'Application settings' => [
                'about' => 'Opening and closing online applications, and the fee and rules applicants see.',
                'permissions' => [
                    ['View application settings', 'View', 'See whether applications are open, the session, deadline, screening fee and instructions.'],
                    ['Edit application settings', 'Edit', 'Open or close online applications, choose the session and deadline, set the screening fee and minimum UTME score, choose which bill item is the acceptance fee, and write the instructions applicants see. Opening applications makes the public form available to everyone.'],
                ],
            ],

            'Students' => [
                'about' => 'People who have been admitted and hold a matric number.',
                'permissions' => [
                    ['View students', 'View', 'See the list of students and open a student\'s full profile: personal details, programme, level, guardian and status.'],
                    ['Edit students', 'Edit', 'Correct a student\'s personal details, guardian, login email and current level, and upload their passport photo.'],
                    ['Change student status', 'Manage', 'Mark a student active, deferred, suspended, withdrawn or graduated. Suspended and withdrawn students are blocked from signing in.'],
                    ['Verify new students', 'Manage', 'Confirm, after checking the original documents in person, that a newly admitted student has been physically verified. Can also be undone. Only possible once the student has accepted the offer, paid the acceptance fee and finished their biodata and uploads.'],
                    ['Reset student passwords', 'Manage', 'Generate a new temporary password for a student. The old password stops working at once.'],
                ],
            ],

            'Clearance' => [
                'about' => 'After verification, a new student must clear every required item (fees, documents, medical, department ...) before a matric number is issued.',
                'permissions' => [
                    ['View clearance', 'View', 'See which students are being cleared, each student\'s checklist, and print a clearance slip once they are cleared.'],
                    ['Clear students', 'Manage', 'Mark a manual clearance item cleared or rejected for a student, with a reason when rejecting. You can only sign items that belong to your own office (see Clearance items), unless you also hold "Clear students for any office".'],
                    ['Clear students for any office', 'Manage', 'Sign off a manual clearance item that belongs to any office, not just your own. Give this to the registrar or a clearance supervisor.'],
                ],
            ],

            'Clearance items' => [
                'about' => 'The checklist this university requires before a matric number: automatic checks (offer accepted, fees paid ...) and manual sign-offs by an office.',
                'permissions' => [
                    ['View clearance items', 'View', 'See the clearance checklist: each item, whether it is automatic or signed by an office, and whether it is required.'],
                    ['Create clearance items', 'Create', 'Add an item to the checklist, such as a library or hostel clearance, and say which office signs it.'],
                    ['Edit clearance items', 'Edit', 'Rename an item, change its office, make it required or optional, or switch it on or off. Switching an item off stops it blocking students.'],
                    ['Delete clearance items', 'Delete', 'Delete a clearance item. Students\' past decisions on it are removed too. Switching it off instead keeps the history.'],
                ],
            ],

            'Matric numbers' => [
                'about' => 'Matric numbers are issued after a student is fully cleared. These permissions cover handing them out, setting special ones, bringing in numbers made elsewhere, and taking them back.',
                'permissions' => [
                    ['View matric numbers', 'View', 'See which cleared students are waiting for a matric number, who already has one, and which numbers were cancelled.'],
                    ['Generate matric numbers', 'Generate', 'Issue matric numbers to cleared students in a batch, using the saved matric number format. Numbers are handed out alphabetically by surname.'],
                    ['Assign special matric numbers', 'Assign', 'Type in a matric number by hand for one cleared student, for example a reserved or special number. It must not already be in use.'],
                    ['Import external matric numbers', 'Import', 'Upload a file of matric numbers made outside the portal and apply them to the matching cleared students.'],
                    ['Cancel matric numbers', 'Cancel', 'Withdraw a student\'s matric number with a reason. The number is kept on record and is never issued to anyone else.'],
                    ['Restore cancelled matric numbers', 'Restore', 'Give a cancelled matric number back to the student it was withdrawn from.'],
                    ['Undo matric number batches', 'Undo', 'Take back every number a generated or imported batch handed out so they can be issued again. Students whose number changed since are left alone.'],
                ],
            ],

            'Matric number format' => [
                'about' => 'The pattern used to generate matric numbers, for example 2025/BSC-CSC/0001.',
                'permissions' => [
                    ['View matric number format', 'View', 'See the current matric number pattern and an example of the next number.'],
                    ['Set the matric number format', 'Set', 'Change the pattern used for students admitted from now on. Existing matric numbers never change.'],
                ],
            ],

            'Invoices & payments' => [
                'about' => 'What each student owes (built from the school bills) and the money received against it.',
                'permissions' => [
                    ['View invoices', 'View', 'See every invoice and payment: who owes what, what has been paid, and print receipts.'],
                    ['Generate invoices', 'Manage', 'Create session fee invoices for all active students (or one programme and level) from the school bills. Students who already have one are skipped, so it is safe to repeat.'],
                    ['Record offline payments', 'Manage', 'Record money received outside the portal (bank teller, transfer, POS or cash) against an invoice. This changes what a student owes, so only give it to bursary staff.'],
                    ['Cancel invoices', 'Manage', 'Cancel an invoice that has no payments, for example one raised by mistake. The student no longer owes it.'],
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
