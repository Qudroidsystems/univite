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

**Result queries and graduation.** A student can query a published result from *My Results* within the number of days set in
*Result Settings* (default 14; 0 = no limit). Staff answer under *Result Queries* (permissions "View result complaints",
"Respond to result complaints"); an upheld query can correct the score in the same step for someone who may amend published results.
*Graduation Lists* checks every enrolled final-level student of a programme: all registered results published, no failed course
outstanding (electives excepted), every core/required course taken (switchable in Result Settings) and the programme's
"units needed to graduate". Approving a list marks the eligible students Graduated with final CGPA and class.

**Academic rules.** *Academic Setup → Academic Rules* holds the credit load (min/max units, carry-over handling, prerequisites),
results rules (whether a failed elective must be passed, CGPA by latest attempt or every attempt (TCP/TNU), probation and
withdrawal CGPA) and graduation rules (units, elective units, minimum CGPA, core courses, maximum years). The university sets
defaults (stored as settings); a faculty, department or programme can override any rule, and the credit load can also be set for
one level and/or semester (table academic_rules; App\Support\AcademicRules resolves the most specific value). "Edit department
academic rules" lets a HOD, the department's administrators (department_admins) or the faculty dean edit their own scopes.
The programme form no longer has credit-load fields; existing values were moved into programme overrides by the migration.

**Standing, advisers and the result summary.** Academic Rules has an *Academic standing* group: probation and withdrawal
CGPA, a lower unit limit while on probation, and whether students advised to withdraw are held from registering. Course
advisers are assigned per programme level on a programme's Academic Rules page; they see "My Advisees" and approve those
registration forms. *Result Summary* (Admissions & Students) lists a department's students for a semester as passed /
carry-overs / probation / advised to withdraw / results pending, printable; the department can allow a student advised to
withdraw to register for the current session (standing_waivers).

**Institution profile.** *Academic Setup → Institution Profile* (permissions "View/Edit institution profile") stores the
institution's name, short name, motto, type, codes, address, contacts, social pages, principal officers, logo, favicon,
registrar's signature, stamp, main colour, tagline and footer as `institution.*` settings (App\Support\Institution).
The name replaces APP_NAME everywhere; printed documents use partials/letterhead and partials/signatory. Uploaded images
live on the public disk, so `php artisan storage:link` must have been run.

## Step 4d: Audit log and session foundation

**Operation log.** Every bulk or sensitive action opens an *operation* with a short ID (`OP-XXXXXXXX`) and writes append-only events (who, what, before, after). Events cannot be edited or deleted (the model throws); a correction or undo is a new operation linked to the original. Passwords, NINs and tokens are stripped before anything is stored. Logged so far: session status changes, session copies and their undo, matric batch generation and undo, result publishing, and score amendments. Browse it at **Operation History** (filter by operation ID, module, session, person, dates).

**Session lifecycle.** Sessions are Draft, Setup, Active, Closing, Closed or Archived. Moving to Active is checked first (semester exists, dates make sense, registration windows make sense, a grading scale exists, no other active session unless allowed). Closing, closing out, reopening and archiving need a reason. Every change is recorded on the session's **status** page (click the status badge in Academic Sessions). Making a session current activates it and moves the previous one to Closing. A closed or archived session cannot be current.

**Registration windows.** Each semester has optional *Registration opens / closes* dates. Outside them students are told when registration opens or closed. A late period can be allowed (see rules).

**Copy session setup.** Choose a source and destination session and what to copy (registration windows, school bills, course lecturers), then **Preview**, then confirm. Bills arrive switched off so they can be reviewed; dates move forward by the year difference. Nothing about students, registrations, scores, results, payments or invoices is ever copied, and the source is never touched. Running it again copies nothing new. **Undo** removes only what nothing has used since (bills not switched on and with no invoices, assignments without a score sheet, windows not edited since); the rest is kept and reported.

**Session rules** (a setting each, with a default; AFUED to confirm): more than one active session (off), semester required to activate (on), closed sessions may be reopened (on), late registration (off, 14 days).

Run `php artisan migrate` after updating.

## Emailed documents, previews and safe reversal

**Emailed documents.** Every receipt, payment reversal notice, course form (on submission and again on approval), clearance slip, matric number (issued, withdrawn or corrected), application slip, result correction or withdrawal, result-query answer and graduation notice is emailed to the student or applicant it belongs to, with the university's letterhead, and attached as a file. A failed email never stops the operation; every attempt is listed under **Emailed Documents**, where failures show and documents can be sent again. Students can also click **Email me a copy** on a receipt or course form. Under the same page, choose which documents are emailed and set the mail server (SMTP host, port, security, username, password stored encrypted, sender), then send a test. Left on "Server default", the `MAIL_*` values in `.env` are used.

**Preview before large operations.** Generating matric numbers and generating invoices in bulk now show who is affected, the numbers or amounts, and what will be skipped, before anything is created.

**Operation history and reversal.** Payments, invoice runs, matric batches, imports, cancellations, published results, score corrections, result-query answers, graduation approvals, course-form decisions, academic-rule changes, institution-profile changes and standing allowances are all logged with who, when, the session, the records affected (by name) and their values before and after. From an operation's page, **Reverse / correct** opens a preview that sorts every affected record into *will change*, *skipped* (changed since) and *blocked* (something depends on it), with warnings and the expected outcome. Confirming needs a reason and a tick, is refused if anything changed after the preview, and is recorded as a new operation linked to the original. Nothing is erased:

| Operation | How it is reversed |
|---|---|
| Matric batch | **Take back** numbers nobody has used; numbers already on a course form, result, receipt or emailed document are blocked. **Correct** gives everyone a new number in the current format; the old numbers stay on record as superseded and are never issued again. Students are emailed. |
| Payment | A reversal entry for the negative amount is added; the original stays, marked reversed (its receipt says so); the balance returns to the invoice; the payer is emailed. Needs *Reverse payments*. |
| Invoice run | Unpaid invoices are cancelled (kept on record); invoices with payments are blocked. |
| Published result | Formally withdrawn: the sheet goes back to approved, scores are kept, students are emailed. Blocked once a student on it has graduated. Needs *Withdraw published results*. |
| Score correction | Put back as a new correction; refused if the score changed again since. |
| Graduation approval | Students return to their earlier status, the list to draft; students are emailed. Needs *Reverse graduation approvals*. |
| Course-form approval | Back to submitted; blocked once scores are entered. |
| Academic rules / institution profile | Earlier values put back; refused (or skipped) where changed again since. Replaced logos and signatures are kept on disk for this. |
| Session copy | As before: only what nothing has used is removed. |

Operations are never edited or deleted (only their outcome status changes, e.g. *reversed* or *corrected*). Payments recorded before this update are added to the history by the migration so they can be reversed too.

Run `php artisan migrate` after updating.

## Matric number approval and running numbers

Under **Matric Number Format** the administrator sets the pattern, when numbers are issued (after clearance or at admission, automatically or in staff batches) and, new:

- **Approval before publishing.** When on, every generated number (staff batch, or automatic on clearance/admission) is *reserved* on the student, so nobody else can get it, but the student does not see it and cannot sign in with it yet. Batches wait under **Matric Numbers → Awaiting approval**. Someone with *Approve matric numbers* (Senate, the admissions committee, the Registrar) reviews the list, which is checked for duplicates, retired numbers and students no longer enrolled, then **approves and publishes** it (students get their number, login and an email) or **rejects** it with a reason (numbers are released for re-issue). "Someone else must approve" stops the preparer approving their own batch. Approved batches can still be taken back or corrected from the operation history.
- **Running numbers.** For each prefix the last number used is detected from numbers issued, reserved, cancelled or superseded. Enter the last number issued before the portal and it carries on from there. It cannot be set below a number in use, so a duplicate cannot be created; the database also refuses duplicate matric and reserved numbers.

Run `php artisan migrate` after updating.

## Venues, timetables, examination seating and staff leave

Run `php artisan migrate` and `php artisan db:seed` after updating (new tables and permissions).

**Venues** (sidebar: Venues & Timetables → Venues). Every hall, classroom, laboratory and studio with its installed seats, usable seats for lectures and for examinations, an optional seat map (rows × seats, blocked and accessible seats), facilities, what it may be used for, whether several papers may share it, and its status. Closures (dates and reason), faults (a *blocking* fault takes the venue out of use until resolved) and one-off bookings are recorded on the venue page; a closure lists every published class or paper it breaks with replacement venues. **Venue Availability** finds the smallest free venue for a date, time, size and facilities; **Venue Usage** shows how full each venue is.

**Timetables** (Venues & Timetables → Timetables). The committee creates a lecture or examination timetable for a session and semester, sets its days, periods and rules (no early or late classes, spread a course over the week, avoid gaps, keep a cohort in one building, big classes first, and how much each matters), checks the list of courses with class sizes and any fixed times or venues, and presses **Generate**. The engine never breaks a hard rule (no student or lecturer in two places, no room over capacity, closed or faulty, staff on leave or unavailable). What it cannot place is listed with the reason and what to change. Entries can be moved by hand (a move that breaks a hard rule is refused; the new time is then locked), the committee can try *what if* changes without touching the saved version, and examination timetables get invigilators from a pool. Then: send for approval → approve (or return) → publish (students and staff are notified). A revision copies a published timetable; publishing it replaces the old one. Staff unavailable times go under **Staff Availability**. Students see **My Timetable** and **My Examinations**; lecturers see **My Teaching & Duties**.

**Examination seating** (open an approved examination timetable → Seating). Seats every candidate of every paper in the halls the timetable gave it, papers sharing a hall alternating so candidates of the same paper do not sit next to each other (side by side, or also front/back, or also diagonal). Anything it cannot keep apart is reported, never hidden; a candidate with two papers at once is flagged. Late registrants can be seated without moving anyone, withdrawn candidates released, and single candidates moved (refused if the seat is taken, and warned if it is next to the same paper). Approve, then publish: students see hall and seat number for each paper and print a slip with a QR code that invigilators scan to check them in. Hall lists show the seat map and attendance.

**Staff leave** (sidebar: My Leave for every member of staff; Staff Leave for the leave office).
- Staff see their balances, a countdown to their leave or of the working days left, apply (with a live check of days, balance, resumption date and problems), attach documents, name an acting officer, correct and resubmit returned applications, cancel before the leave starts, and confirm their resumption.
- Approvals follow a route set under **Leave Settings** (default: head of department → HR Officer role → Registrar role; each leave type can have its own; a step with nobody to decide it is skipped). Approvers see **Leave Approvals**. Every decision is audited and the applicant gets a letter with a reference number (LV/2027/00001) by email and in the portal.
- Rules: leave types (days, working or calendar days, documents, confidential medical documents, gender, minimum service, notice, carry-over, acting officer) and entitlement rules by staff category, grade, appointment and years of service. **Staff Profiles** holds department, category, grade, first appointment and supervisor. **Holiday Calendar** dates are not counted as working days and are skipped by examination timetables.
- Warnings: too many of a department away at once, acting officer also away, and classes or invigilation falling in approved leave (the HOD is told to arrange cover).
- **Leave Register**, **Leave Balances** (adjustments with a reason; carry-over into the next year) and the **Leave calendar**.

**Text messages and WhatsApp** (Messaging → Messaging Settings). Choose Twilio, Termii or any other provider through a webhook, enter the credentials (stored encrypted, never shown again) and choose which topics go out by SMS and WhatsApp. Until a provider is chosen messages are only logged. **Message Log** shows every message and retries failures. Everyone has a bell in the top bar and can set their own phone, WhatsApp number and opt-outs under Notification settings.

**Scheduled jobs.** Leave reminders (approvals waiting, leave starting soon, days left, late resumption) and escalation run from `php artisan leave:daily`; failed messages are retried by `php artisan messages:retry`. Both are scheduled, so the server needs the Laravel scheduler running: on a server add the cron entry `* * * * * cd /path/to/univite && php artisan schedule:run >> /dev/null 2>&1`; on a local XAMPP machine run `php artisan schedule:work` in a terminal.

## Results from before the portal (legacy import)

Admissions & Students → **Legacy Results** (permission *Import legacy results*). Run `php artisan migrate` and `php artisan db:seed` after updating.

- **Course results**: one row per student per course (matric number, session, semester, course code, and CA + exam, total or grade). The old grade is kept when the file has one, even if today's scale would give another, with a warning.
- **Previous totals**: one row per student with TNU, TCP (or CGPA) and optionally units passed and the last session covered. They are added to the CGPA the portal works out and shown as "Brought forward" on the transcript. Use them for years whose course-by-course results are not available, not on top of them.
- Upload a CSV or Excel export as it comes out of the old system. The columns are matched from their headings (you confirm or change the match), every row is checked without saving anything, and the import brings in only the rows that pass. Rows left out are listed with the reason and can be downloaded as a CSV to fix. Nothing already in the portal is overwritten. Missing past sessions can be added automatically; missing courses can be added (inactive) from their title and units.
- A whole import can be undone from **Operation History**.

## Code conventions

- **Dependency injection.** Services, reversal handlers, listeners and controllers receive their collaborators through the constructor (or a controller action's parameters). Nothing calls `app(...)` or `new SomeService()` inside them. The only exceptions are the two registries that pick a class from configuration: `App\Services\Payments\Gateways` and `App\Support\Reversals`. `tests/Feature/ConventionsTest.php` fails if this slips.
- **Breaking cycles.** When two services would need each other, one side raises an event instead. For example, paying a fee or finishing an onboarding step raises `StudentProgressChanged`, and the `RefreshClearance` listener re-checks clearance. Batch codes come from `MatricBatch::open()`.
- **DTOs.** Data that crosses a service boundary is a typed, `final readonly` class in `app/Data/<Area>/`. Inputs are built from validated requests with `fromArray()`. Outputs have a fixed shape, for example `Transcript`, `LeaveBalance`, `GraduationCheck`, `RegistrationContext`, `CreditLimits`, `BulkInvoiceResult`, `MatricRun`, `Admission`, `StatusCheck`, `ScoreImport` and the legacy-import report. Only objects that collect results as they go (`ParsedFile`, `BulkInvoicePreview`) are mutable.
- **Settings stay arrays.** Settings saved as JSON on a record, such as a timetable's configuration and seating rules, are still arrays.
