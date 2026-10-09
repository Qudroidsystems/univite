# UniVite — University Portal

A Laravel 12 university portal. It started life as a school management system; everything school-specific has
been removed and the foundation kept: **login, users, roles and permissions** (Spatie), plus the Velzon admin theme.

## What is in it so far

| Area | Where | Permissions |
|---|---|---|
| Academic sessions (e.g. 2025/2026, one current at a time) | Academic Setup → Academic Sessions | `View/Create/Update/Delete session`, `Set current session` |
| Semesters per session (First, Second …, one current) | Academic Setup → Semesters | `… semester`, `Set current semester` |
| Faculties, departments (with Dean / HOD) | Academic Setup | `… faculty`, `… department` |
| Programmes (degree type, duration → levels, min/max credit load) | Academic Setup → Programmes | `… programme` |
| Courses with credit units | Academic Setup → Courses | `… course` |
| Curriculum: courses per programme, level and semester, with credit-unit totals | Academic Setup → Curriculum | `View curriculum`, `Update curriculum` |
| Bill items (tuition, hostel, library …) | School Bills → Bill Items | `… bill-item` |
| School bills: amount per session + programme + level (+ fresh/returning), copy to a new session | School Bills → School Bills | `… bill`, `Copy bills` |
| Online application (public, no login needed to start): register with JAMB number and a password, pay the screening fee, enter details, O'Level/NCE/ND results and upload documents, submit, print the screening slip | `/apply` (linked from the home page) | none (public); staff manage it with the permissions below |
| Application settings: open/close applications, session, closing date, screening fee, minimum UTME score, instructions, payment instructions | Admissions & Students → Application Settings | `View application settings`, `Edit application settings` |
| Applicants: bio-data, guardian, programme, entry mode/level/session; review online applications and their documents, admit one by one or a whole batch, or reject | Admissions & Students → Applicants | `View/Create/Edit/Delete applicants`, `Admit applicants`, `Reject applicants` |
| Admission status: applicants check the outcome with JAMB number + surname, no sign-in | `/admission-status` | none (public) |
| Getting started (newly admitted students): accept the offer, pay the acceptance fee, complete biodata, upload birth certificate and state-of-origin | students: Getting Started | none (own record only) |
| New student verification: confirm students after checking original documents in person | Admissions & Students → New Student Verification | `View students`, `Verify new students` |
| Students: created by admitting an applicant (matric number + login generated); profile, passport photo, status, password reset | Admissions & Students → Students | `View students`, `Edit students`, `Change student status`, `Reset student passwords` |
| Matric number format, e.g. `{YEAR}/{PROGRAMME_CODE}/{SEQ:4}` | Admissions & Students → Matric Number Format | `View matric number format`, `Set the matric number format` |
| Invoices: each student's session fees built from the school bills (fresh vs returning), offline payments recorded by the bursary, printable receipts, students see their own fees | Fees & Payments → Invoices & Payments; students: My Fees | `View invoices`, `Generate invoices`, `Record offline payments`, `Cancel invoices` |
| Users, roles, permissions | Users & Access | `View/Add/Edit/Delete users`, `Reset user passwords`, `Disable or enable user accounts`, `… roles`, `Assign/Remove users …`, `… permissions` |

Accounts are created by administrators — there is no public registration.

## Setup

```bash
composer install
cp .env.example .env        # then set DB_* and ADMIN_* in .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link    # needed once so student passport photos can be displayed
```

The seeder creates the permissions, the `Super Admin` and `Admin` roles and a first administrator
(`ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env`). **Change that password after first login.**
Re-running `php artisan db:seed` is safe and picks up any new permissions.
Every permission has a plain-English name and description (and each group an explanation) defined in
`app/Support/PermissionCatalog.php`; these are shown in the role editor and the Permissions page.
Re-seeding also renames old permissions and carries existing role/user assignments across.
The `Super Admin` role passes every permission check automatically.

## Tests

```bash
php artisan test
```

## Adding a simple setup page

Simple list + modal-form pages extend `App\Http\Controllers\Academics\CrudController`: declare the model,
validation rules, form fields and table columns, register a route, add the permissions to
`database/seeders/PermissionTableSeeder.php` and a link in `resources/views/layouts/sidebar.blade.php`.
`SessionController` and `FacultyController` are the shortest examples.

## Reference data (phase 4, step 1)

Everything a university may name differently is data, not code. Admins edit it under **Academic Setup**:
study categories (level naming, e.g. "100 Level" or "NCE 1"), semester types (First/Second or Harmattan/Rain/Third),
student statuses (with switches: can sign in, register, pay, counts as enrolled), entry modes (entry level, UTME or prior
result needed, open online) and reference lists (titles, gender, religion, blood group, states ...).
Defaults are seeded by `App\Support\ReferenceData`; run `php artisan migrate` then `php artisan db:seed`.

## Fees (phase 4, step 2)

A school bill can now target faculty, department, student category, programme, level, semester, fresh/returning and
home/foreign students; a blank target means everyone. When several bills exist for one item the most specific wins
(so a "Foreign students only" tuition bill replaces the general one). Bills may be split into 2 or 3 instalments with
percentages, billed per semester on a separate invoice, and given a payment window (opens/closes). Optional bill items
are left off automatic invoices. The home nationality defaults to Nigerian (setting `institution.home_nationality`).
Run `php artisan migrate`.

## Clearance and matric numbers (phase 4, step 3)

Flow: Application → Admission → Acceptance → Verification → **Clearance** → **Matric number**.
Each university defines its clearance checklist (Academic Setup → Clearance Items): automatic items clear themselves
(offer accepted, acceptance fee, biodata, documents, verification, school fees); manual items are signed by an office
(a role). Students see a live checklist, can re-submit rejected items, get notifications and a printable slip.
Matric numbers are issued after clearance by default (setting on the Matric Number Format page: after clearance or at
admission; automatically or in a staff-run batch). The Matric Numbers screen can generate a batch, set special numbers,
apply a CSV of external numbers, cancel/restore numbers and undo a batch. Run `php artisan migrate` and `php artisan db:seed`.

## Custom fields, async pages and network awareness

**Custom form fields** (Academic Setup → Custom Form Fields). Administrators add their own questions to the applicant,
student and staff forms: short text, paragraph, number, date, choose-from-a-list, yes/no and file upload. Fields can be
grouped under a heading, marked required, hidden without losing answers, and flagged "Person fills it in" so applicants
(online application) and new students (Getting started) answer them themselves. Answers show on the applicant, student
and staff profiles; uploaded files are kept on private storage and served only to people allowed to see that record.
The type or form of a field cannot change once people have answered it; deleting a field deletes its answers and files.
Code: `App\Support\CustomFields`, models `CustomField` / `CustomFieldValue`, `CrudController::$customTarget`.
Run `php artisan migrate` and `php artisan db:seed`.

**Async pages.** Forms, deletes, pagination, filters and tabs work without reloading the page. The server side is the
`AjaxResponses` middleware (JSON for ajax saves, 422 errors shown inline, repeat-submit protection through
`X-Idempotency-Key`); the browser side is `public/js/univite-app.js`. A page or form opts out with
`<meta name="uv-ajax" content="off">` or `data-no-ajax`. Note: a form field called `target` or `action` is fine, the script
reads the attributes directly.

**Network awareness.** A signal indicator in the top bar shows Good / Fair / Poor / Offline (browser online state, the
Network Information API and a small `/ping` probe). Going offline opens a SweetAlert modal that closes itself when the
connection returns; poor connections get longer timeouts and a retry prompt instead of a silent failure.

## Grading and course registration (phase 4, step 4a)

**Grading scale** (Academic Setup → Grading Scale, Classes of Degree): score bands → letter, grade points, pass/fail; CGPA → class of
degree. Seeded with the common 5-point scale; edit freely (overlaps are refused, gaps are flagged). `App\Support\GradeScale` does
grade lookup, GPA and class.

**Registration** (students: Course Registration; staff: Admissions & Students → Course Registrations / Registration Settings).
Each semester a student is offered the curriculum for their level and semester plus any carry-overs (failed and not yet passed).
Admin settings: open/closed, min/max units, fee check (none / first instalment / full), approval required, clearance required for new
students, carry-overs forced, prerequisites checked (set on each course), carry-overs exempt from the maximum. Flow: draft → submitted →
approved/returned (with a reason); approved forms print; staff can approve in bulk or reopen a form. Code: `RegistrationService`,
`RegistrationSettings`. Tables: grade_bands, degree_classes, course_prerequisites, registration_forms, course_registrations,
course_results. Run `php artisan migrate` and `php artisan db:seed`.

## Results (phase 4, step 4b)

Flow: **assign lecturer → enter / upload scores → submit → approval steps → publish → students see results**.

- *Course Lecturers* (Academic Setup) assigns a lecturer to a course for a session and semester. Assigned lecturers see "My Courses & Scores".
- A score sheet exists per course/session/semester with a row for every student whose registration form is **approved**. CA and exam are
  entered in the grid or uploaded as CSV/Excel (first row: Matric Number, CA, Exam; a fill-in template can be downloaded). Limits come from
  Result Settings (default 30/70, must total 100). Grades come from the Grading Scale. Every student needs scores (0 = absent) before submitting.
- *Result Approval Steps* define the chain (default Head of Department, then Dean); each step can name the role that signs it, or be open to
  anyone with "Approve results". Approvers can return a sheet with a reason. After the last step the sheet is "approved"; **Publish results**
  makes it visible. With no steps, submission goes straight to approved. Every action is logged on the sheet.
- Students: *My Results* shows published results by semester with GPA and CGPA (each course counts once, its latest published attempt) and class
  of degree. Staff with "View student results" get a printable transcript from the student page.
- Published/approved results feed registration: failed courses become carry-overs, passed courses satisfy prerequisites.
- Excel upload needs `phpoffice/phpspreadsheet` (run `composer require phpoffice/phpspreadsheet` if it is not installed); CSV always works.

Run `php artisan migrate` and `php artisan db:seed`.

**Broadsheet and corrections.** *Broadsheet* (Admissions & Students) shows a programme's published results for one level, session and semester:
every student's score and grade per course, semester GPA and CGPA, printable. A published score can be corrected by someone with
**Amend published results**: a reason is required and the old and new scores are written to the sheet's history, and the grade is recomputed.
