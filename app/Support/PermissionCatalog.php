<?php

namespace App\Support;

/**
 * The single source of truth for every permission in the portal.
 *
 * A permission's NAME is what administrators read when they build a role, so each name is a plain
 * "verb + what" sentence ("Edit academic sessions"). Each one also carries an ACTION chip and a
 * DESCRIPTION that says exactly what it allows, and each group carries a short "about" line.
 *
 * Seeded into the database by PermissionSeeder and its named module seeders (LeavePermissionSeeder ...); shown by the role editor (PermissionMeta).
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
                    ['View scoped roles', 'View', 'See who holds a role only for one faculty, department or unit, including the roles heads get automatically.'],
                    ['Assign scoped roles', 'Assign', 'Give someone a role for one faculty, department or unit only (for example an exams officer for one department), or take it away, and choose which role heads get.'],
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
                'about' => 'A permanent log of bulk and sensitive actions (payments, matric batches, published results, graduation, rule changes ...) with who, when, the records affected and their values before and after. Entries are never edited or deleted. Reversals are previewed, checked for records that depend on them, need a reason, and are recorded as new operations linked to the original. Who may reverse each kind of operation is set by that area\'s own permission (for example "Reverse payments").',
                'permissions' => [
                    ['View operation history', 'View', 'Search the log of bulk and sensitive actions by session, module, person, date, operation ID or record, and see exactly what each one changed.'],
                ],
            ],

            'Emailed documents' => [
                'about' => 'Receipts, course forms, clearance and application slips, matric numbers and result notices are emailed to the person they belong to. Every attempt is logged.',
                'permissions' => [
                    ['View email log', 'View', 'See every emailed document, who it went to and whether it was delivered.'],
                    ['Resend emailed documents', 'Manage', 'Send a receipt, course form or slip again from the email log.'],
                    ['Edit email settings', 'Edit', 'Choose which documents are emailed, set up the mail server (SMTP) and send a test email.'],
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
                    ['Withdraw published results', 'Manage', 'Formally withdraw a published score sheet (for example when Senate sets it aside) after a preview. Scores are kept, students are emailed, and the sheet can be published again. Not allowed once a student on it has graduated.'],
                    ['Publish results', 'Manage', 'Publish an approved score sheet so students can see their results, or send an approved sheet back for correction.'],
                ],
            ],

            'Legacy results' => [
                'about' => 'Results from before the portal: course-by-course results from old records, and each student\'s previous cumulative totals (TCP and TNU) carried into their CGPA. Imports are checked before anything is saved and a whole import can be undone from the operation history.',
                'permissions' => [
                    ['Import legacy results', 'Import', 'Upload old results or previous TCP/TNU totals from a spreadsheet, match its columns, check it and import it. Can also undo an import.'],
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
                    ['Approve graduation lists', 'Approve', 'Approve a graduation list. Every eligible student on it is marked Graduated with their final CGPA and class, and is emailed.'],
                    ['Reverse graduation approvals', 'Manage', 'Reverse an approved graduation list from the operation history after a preview: students go back to their earlier status and the list to draft. The approval stays on record.'],
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
                    ['Generate matric numbers', 'Generate', 'Issue matric numbers to cleared students in a batch, using the saved matric number format, after a preview. Numbers are handed out alphabetically by surname. When approval is switched on they are only reserved until the batch is approved.'],
                    ['Approve matric numbers', 'Approve', 'Approve a batch of reserved matric numbers on behalf of Senate, the admissions committee or the person in charge, which publishes them to the students; or reject it with a reason, which releases the numbers. When "someone else must approve" is on, nobody can approve a batch they generated.'],
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
                    ['Set the matric number format', 'Set', 'Change the pattern used for students admitted from now on, when numbers are issued, whether they need approval, and the last number already used for each prefix (for example the last number issued before the portal). Existing matric numbers never change.'],
                ],
            ],

            'Venues' => [
                'about' => 'The register of lecture halls, classrooms, laboratories and examination halls that both timetables use, with closures, faults and one-off bookings.',
                'permissions' => [
                    ['View venues', 'View', 'See every venue with its capacity, facilities, seat map, status, closures, faults, bookings and how much it is used, and find free venues for a date and time.'],
                    ['Create venues', 'Create', 'Add a venue to the register: its type, location, installed, teaching and examination capacity, facilities, seat map and what it may be used for.'],
                    ['Edit venues', 'Edit', 'Change a venue\'s details, capacities, facilities, seat map, blocked and accessible seats, and its status (available, reserved, under maintenance, closed).'],
                    ['Delete venues', 'Delete', 'Delete a venue that no timetable or seating plan uses. Marking it inactive keeps its history instead.'],
                    ['Set venue closures', 'Set', 'Close a venue for a range of dates (repairs, an event) and lift a closure. Timetables stop using it on those dates and the affected classes and papers are listed with replacement rooms.'],
                    ['Record venue faults', 'Record', 'Report a fault, inspection finding or unsafe condition in a venue. A fault marked "venue cannot be used" blocks it from new allocations until it is resolved.'],
                    ['Clear venue faults', 'Clear', 'Mark a reported venue fault as being fixed or resolved, with what was done. Resolving a blocking fault makes the venue available again.'],
                    ['Create venue bookings', 'Create', 'Book a venue for a meeting, seminar or event on a date and time. The booking is refused if the venue is closed or already used then.'],
                    ['Cancel venue bookings', 'Cancel', 'Cancel anyone\'s venue booking. People can always cancel their own bookings.'],
                ],
            ],

            'Timetables' => [
                'about' => 'Lecture and examination timetables: the committee prepares and generates them, someone approves them, and publishing shows them to students and staff.',
                'permissions' => [
                    ['View timetables', 'View', 'See every timetable, including drafts and ones awaiting approval, with their conflicts, explanations and invigilation duties.'],
                    ['Create timetables', 'Create', 'Prepare timetables as a committee member: set periods, dates, rules and priorities, choose what is scheduled, generate, move entries by hand (every move is checked) and run what-if tests. When a timetable names its committee, only those members can change it.'],
                    ['Approve timetables', 'Approve', 'Approve a timetable sent by the committee, or return it with a note. A timetable that breaks any hard rule cannot be approved.'],
                    ['Publish timetables', 'Publish', 'Publish an approved timetable so students and staff see it in their portals and are notified, replacing the previous version. Also start a revision of a published timetable.'],
                    ['Set staff availability', 'Set', 'Record when lecturers and invigilators cannot be scheduled (a weekday, or a range of dates, all day or between times).'],
                ],
            ],

            'Examination seating' => [
                'about' => 'Which candidate sits where for every paper, keeping candidates of the same paper apart; students see their seat numbers once it is published.',
                'permissions' => [
                    ['View exam seating', 'View', 'See seating plans, hall maps, candidate lists for invigilators and any rule the arrangement could not keep.'],
                    ['Generate exam seating', 'Generate', 'Generate (or regenerate) the seating plan for an examination timetable using the chosen separation rules, buffer seats and accessible seats.'],
                    ['Approve exam seating', 'Approve', 'Approve a generated seating plan after checking it.'],
                    ['Publish exam seating', 'Publish', 'Publish an approved seating plan so each student sees their hall, seat number, date and time, and is notified.'],
                    ['Assign exam seats', 'Assign', 'Move a candidate to another free seat (a controlled reassignment with a reason), or seat a candidate who registered after the plan was made. The student is told.'],
                    ['Record exam attendance', 'Record', 'Check candidates in at the hall, or mark them absent, on the invigilator\'s list.'],
                ],
            ],

            'Staff leave' => [
                'about' => 'Leave applications, approvals, balances and resumption. Every member of staff can apply and see their own leave without any permission.',
                'permissions' => [
                    ['View leave applications', 'View', 'See every leave application, its approval trail and documents (except confidential medical documents), the leave calendar and who is away.'],
                    ['Approve leave for any staff', 'Approve', 'Act on any leave application at any approval step, for example as the HR or Registry officer, even when it is waiting for someone else. Every decision is recorded with the name of who made it.'],
                    ['View confidential leave documents', 'View', 'Open confidential supporting documents such as medical certificates. Approvers without this see only that a document was provided.'],
                    ['Set leave policy', 'Set', 'Set up leave types, entitlement rules (by category, grade, appointment and years of service), approval routes, reminders, escalation and the holiday calendar.'],
                    ['Change leave balances', 'Change', 'Add or remove days on a member of staff\'s balance with a reason, and carry unused days into the new leave year.'],
                    ['Record staff resumption', 'Record', 'Record the date a member of staff actually resumed. A late return is flagged and an early one gives back the unused days.'],
                ],
            ],

            'Staff records' => [
                'about' => 'Each member of staff\'s employment record: where they work, grade and step, appointment and service, retirement, and how they are paid. Heads can be given these for their own department, faculty or unit.',
                'permissions' => [
                    ['View staff records', 'View', 'See staff records: department or unit, designation, category, grade, appointment and service dates, status and expected retirement. Pay, bank, pension and tax details are not shown without "View payroll".'],
                    ['Edit staff profiles', 'Edit', 'Add a staff record and change its staff number, department or unit, designation, category, grade level, appointment, dates, supervisor, employment status and exit.'],
                    ['Edit staff pay details', 'Edit', 'Change a member of staff\'s salary structure and step, bank account, pension fund administrator and RSA PIN, and tax details. Every change is kept on the operation history.'],
                ],
            ],

            'Units' => [
                'about' => 'Non-academic offices such as the Bursary, Registry, Works or Library. Staff belong to a department or a unit, and the head of a unit gets the unit head role for it.',
                'permissions' => [
                    ['View units', 'View', 'See the list of units and who heads each one.'],
                    ['Create units', 'Create', 'Add a unit, such as the Bursary or Works department, and choose its head.'],
                    ['Edit units', 'Edit', 'Rename a unit or change its head. The unit head role moves to the new head at once.'],
                    ['Delete units', 'Delete', 'Delete a unit that no member of staff belongs to.'],
                ],
            ],

            'Payroll' => [
                'about' => 'Salary scales, allowances and deductions, the monthly payroll and payslips, and the bank, pension and tax schedules. Every member of staff sees their own payslips without any permission.',
                'permissions' => [
                    ['View payroll', 'View', 'See every payroll and payslip, staff pay, bank, pension and tax details, and download the bank, pension, tax and deduction schedules.'],
                    ['Set payroll policy', 'Set', 'Set up salary structures and scales, allowances, deductions and employer contributions, the income tax table, which employment statuses are paid, retirement ages and whether payslips are emailed.'],
                    ['Change staff pay', 'Change', 'Add, change or stop a member of staff\'s own earnings and deductions, such as loans (with a balance), co-operative savings or union dues.'],
                    ['Prepare payroll', 'Manage', 'Prepare a month\'s payroll as a draft and prepare it again after corrections, or delete a draft.'],
                    ['Approve payroll', 'Approve', 'Approve a prepared payroll: payslips are released to staff and loan balances go down. Can also reopen an approved payroll that has not been paid.'],
                    ['Mark payroll paid', 'Manage', 'Record that the bank has paid an approved payroll. After this it can no longer be reopened.'],
                ],
            ],

            'Messaging' => [
                'about' => 'SMS and WhatsApp notices, the provider they go through, and the log of what was sent.',
                'permissions' => [
                    ['View message log', 'View', 'See the log of SMS and WhatsApp messages: who they went to, when, through which provider and whether they were delivered to the provider.'],
                    ['Edit messaging settings', 'Edit', 'Choose the SMS and WhatsApp provider, enter its credentials (stored encrypted), and choose which notices go by SMS or WhatsApp.'],
                ],
            ],

            'Polls & elections' => [
                'about' => 'Surveys, opinion polls and student or staff elections. Everyone in a poll\'s audience can vote in it without any permission.',
                'permissions' => [
                    ['View polls', 'View', 'See every poll and election, including drafts, who may vote in each, turnout and the results at any time, and download the results.'],
                    ['Create polls', 'Create', 'Set up a poll or election: its audience, voting times, whether the ballot is secret, the questions or offices and the answers or candidates. Questions are fixed once anyone has voted.'],
                    ['Publish polls', 'Publish', 'Publish a poll so its audience can vote when it opens, take it back to draft before anyone votes, close voting early and publish the results.'],
                    ['Delete polls', 'Delete', 'Delete a poll or election nobody has voted in. One with votes is kept as a record.'],
                ],
            ],

            'Identity cards' => [
                'about' => 'Student and staff identity cards with a QR code anyone can scan to check the card is genuine and still valid.',
                'permissions' => [
                    ['View identity cards', 'View', 'See the register of identity cards: who has one, when it was issued and expires, whether it was printed or collected, and replacement requests.'],
                    ['Issue identity cards', 'Issue', 'Issue cards one at a time or for a whole group, print them, mark them printed or collected, and approve replacement requests. Issuing a new card replaces the holder\'s old one.'],
                    ['Cancel identity cards', 'Cancel', 'Cancel a card that was lost, stolen or belongs to someone who has left. Scanning a cancelled card says it is not valid.'],
                    ['Set identity card design', 'Set', 'Choose the card colours, wording, signatory, what is printed on the back, how long cards are valid and the fee item for replacements.'],
                ],
            ],

            'Library' => [
                'about' => 'The library catalogue, lending and returns, holds, overdue fines and library clearance. Every signed-in user can search the catalogue, see their own loans and place holds.',
                'permissions' => [
                    ['Edit library catalogue', 'Edit', 'Add, change and withdraw titles and copies (with accession numbers), and import the catalogue from a spreadsheet.'],
                    ['Issue library loans', 'Issue', 'Lend copies to students and staff at the desk, receive returns, renew loans and manage holds. Loan limits and suspended borrowers are checked.'],
                    ['View library loans', 'View', 'See every loan, overdue list, hold and fine, and any borrower\'s library history and clearance.'],
                    ['Record library fine payments', 'Record', 'Record that a fine was paid at the library desk. Student fines are normally billed to the student\'s fees and clear themselves when paid.'],
                    ['Waive library fines', 'Waive', 'Waive or reduce an overdue fine with a reason. Every waiver is kept on the operation history.'],
                    ['Set library policy', 'Set', 'Set loan periods and limits for students and staff, renewals, how long holds wait, the overdue fine per day (none unless set) and the bill item fines are charged under.'],
                ],
            ],

            'Health & clinic' => [
                'about' => 'Health records, medical examinations for new students, and clinic visits. Records are confidential: clinical notes are stored encrypted and every time a record is opened it is logged. Everyone sees their own record without any permission.',
                'permissions' => [
                    ['View health records', 'View', 'Open the health record, medical examination result and clinic visits of any student or member of staff. Each opening is logged with the reason given.'],
                    ['Record clinic visits', 'Record', 'Record a clinic visit (complaint, vital signs, diagnosis, treatment, referral and excuse-from-duty note) and update a person\'s health details such as allergies and conditions.'],
                    ['Record medical examinations', 'Record', 'Record the outcome of a new student\'s medical examination (fit, fit with conditions or not fit). A fit result clears the medical clearance item.'],
                    ['View clinic statistics', 'View', 'See counts of clinic visits by month and outcome and of medical examinations, without names.'],
                ],
            ],

            'Accommodation' => [
                'about' => 'Hostels, rooms and bed spaces, students\' applications, allocation, payment and checking in and out.',
                'permissions' => [
                    ['View accommodation', 'View', 'See hostels, rooms and who is in each bed space, applications, allocations, payments and occupancy.'],
                    ['Set accommodation policy', 'Set', 'Set up hostels, rooms and bed spaces, who each hostel is for, the rate for each session, when students may apply, the allocation order and how long a student has to pay.'],
                    ['Allocate accommodation', 'Allocate', 'Run the allocation for a session, allocate, move or cancel a student\'s bed space, and release bed spaces that were not paid for in time.'],
                    ['Check students in and out of hostels', 'Check', 'Record that a student has moved into or out of their bed space. Wardens can do this for their own hostel without this permission.'],
                ],
            ],

            'Teaching practice' => [
                'about' => 'The teaching practice exercise: partner schools, students\' postings, supervisors, supervision visits and scores, and posting letters. Supervisors record scores for their own schools without any permission.',
                'permissions' => [
                    ['View teaching practice', 'View', 'See exercises, schools, postings, supervisors and every student\'s supervision scores.'],
                    ['Set up teaching practice', 'Set', 'Create an exercise (dates, who takes part, the course it counts for, the number of visits and the marking guide) and keep the list of partner schools.'],
                    ['Post students for teaching practice', 'Post', 'Post students to schools (automatically by preference and capacity, or by hand), publish postings so students can print their letters, and assign supervisors to schools.'],
                    ['Record teaching practice scores', 'Record', 'Record or correct a supervision score for any student, not only those at schools you supervise.'],
                    ['Publish teaching practice scores', 'Publish', 'Send students\' final teaching practice scores into the score sheet of the teaching practice course, from where they follow the normal result approval.'],
                ],
            ],

            'Invoices & payments' => [
                'about' => 'What each student owes (built from the school bills) and the money received against it.',
                'permissions' => [
                    ['View invoices', 'View', 'See every invoice and payment: who owes what, what has been paid, and print receipts.'],
                    ['Generate invoices', 'Manage', 'Create session fee invoices for all active students (or one programme and level) from the school bills. Students who already have one are skipped, so it is safe to repeat.'],
                    ['Record offline payments', 'Manage', 'Record money received outside the portal (bank teller, transfer, POS or cash) against an invoice. This changes what a student owes, so only give it to bursary staff.'],
                    ['Reverse payments', 'Manage', 'Formally reverse a posted payment after a preview. The original payment and receipt stay on record marked reversed, a reversal entry is added, the balance goes back on the invoice and the payer is emailed. A reason is required.'],
                    ['Cancel invoices', 'Manage', 'Cancel an invoice that has no payments, for example one raised by mistake. The student no longer owes it.'],
                    ['Edit payment settings', 'Edit', 'Switch online payment with Paystack, Flutterwave or Remita on or off and enter their keys (stored encrypted). This decides where students\' and applicants\' money goes, so only give it to the bursar or ICT.'],
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
