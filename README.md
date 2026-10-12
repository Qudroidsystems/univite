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

**Assessment schemes** (Academic Setup → Assessment Schemes). A scheme lists the assessments a course is marked on (Test 1 /10,
Assignment /10, Practical /20, Exam /60 ...), each counting towards CA or exam, adding up to 100 (each with a short code used as the
upload column heading). Assign a scheme to the whole university, a faculty, a department or one course, for every session or one
session; the most specific assignment wins (course > department > faculty > university; one session > every session). *Which scheme
does a course use?* on the same page shows the answer and where it comes from. A score sheet takes its scheme when it is opened and
keeps it once a mark is entered, so later changes never move marks already given; a sheet that already has plain CA/exam marks stays
on the Result Settings split, and so does any course with no scheme. Lecturers enter one mark per assessment (grid, CSV/Excel upload
with one column per code, or the mobile app); marks can be saved bit by bit and the CA and exam are the sums, so approval, publishing,
transcripts, broadsheets, queries and graduation work unchanged. A scheme's assessments are locked once marks exist (rename, switch
off or copy it instead); a scheme in use cannot be deleted. Where only a CA and exam are known (teaching-practice scores, a correction
from a result query) they are spread over the assessments in proportion. Correcting a published result on a scheme sheet is per
assessment, and the operation log keeps every assessment's old and new mark so a reversal restores them exactly. Permissions:
*View / Create / Edit / Delete / Assign assessment schemes*. Code: `AssessmentService`; tables assessment_schemes,
assessment_items, assessment_assignments, assessment_scores and result_sheets.assessment_scheme_id. Run `php artisan migrate` and
`php artisan db:seed --class=PermissionSeeder`.

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

## Staff records, payroll and heads

Run `php artisan migrate` and `php artisan db:seed` after updating. The seeder adds the roles *Head of Department*, *Dean* and *Head of Unit* if they do not exist yet, and gives every current head theirs.

- **Staff Records** (Staff & Payroll menu) replaces the old Staff Profiles page. Each record has the department or unit, employment status, grade and step, bank, PFA and RSA PIN, tax and NHF details, personal earnings and deductions (a deduction with a balance is a loan and stops once repaid), payslips and recent leave. **Retirements Due** lists who retires within a chosen number of months (the earlier of the retirement age and the most years of service, both in Payroll Settings).
- **Units** are offices outside the academic departments (Bursary, Works ...). Units that were typed on staff profiles were turned into units automatically.
- **Payroll setup**: CONUASS and CONTISS are created without amounts. Enter each structure's annual basic salary per grade and step under Salary Structures → Scale (or upload a spreadsheet with Grade, Step and Annual basic). Add allowances under Allowances & Deductions and tick *pensionable* on those that count towards pension (normally rent and transport). Pension (8% / 10%), NHF (2.5% of basic) and PAYE are built in. **Check the tax bands in Payroll Settings against current law before the first run.** Add your banks (code = CBN sort code) and PFAs under Reference Lists to choose them from a list.
- **A month's payroll**: Payroll → Prepare (a draft, which can be prepared again as often as needed) → Approve (payslips are frozen, loan balances go down, staff are told and can see them under My Payslips, and they are emailed if switched on) → Mark paid. Approval is refused if a record, item, element, scale or setting changed after the draft was prepared. An approved month can be reopened from Operation History until it is marked paid. People joining or leaving mid-month and approved leave without pay are paid for part of the month. Bank, pension (by PFA), PAYE (by state) and deduction schedules can be printed or downloaded as CSV.
- **Heads**: setting someone as head of a department, faculty or unit gives them a *scoped role* for that area only; changing the head moves it. Which role each kind of head gets is set on **Scoped Roles & Heads**, where a role can also be given by hand for one area. A scoped role only opens the students, transcripts, course registrations (and approving them, for heads of department), leave and staff records of that area. Pay details stay hidden.

## Campus services (step 6)

Six modules share three pieces: an **audience** (who something is for: students or staff, narrowed by faculty, department, programme, level and gender; `App\Data\Campus\Audience` read by `AudienceResolver`), **one-off charges** raised as ordinary invoices (kinds `accommodation`, `id_card`, `library`, so students pay under My Fees through the usual gateways), and **settings groups** (`App\Support\SettingsGroup`), which describe a module's rules once for the settings page, validation and defaults. Nothing institution-specific is hard-coded: fees, loan periods, fines, deadlines and the marking guide all start at neutral defaults and are edited on each module's rules page.

- **Polls and elections** (Campus Services → Polls & Elections; students and staff vote under the personal *Polls & Elections* link). Secret ballots store answers under a random token with no voter and no time, so a ballot cannot be traced. Results can show live, after closing, or only when published.
- **Identity cards** (Campus Services → Identity Cards). Issue to one person or a whole group; people without a passport photograph are listed and skipped. Each card carries a QR code that opens `/id/{token}` (public) and says whether it is valid, replaced, cancelled, expired, or the holder has left. Students ask for replacements under *My ID Card*; a lost/damaged replacement fee (if set) is billed and must be paid before the card is issued.
- **Library** (Library → Desk, Catalogue, Loans, Fines, Rules). Import a catalogue from CSV/Excel (Title required; Authors, ISBN, Publisher, Year, Edition, Call number, Subject, Copies optional). Lend and check in by accession number; a returned copy goes to the first person on the waiting list. Overdue fines (0 by default) go to students' fees and are paid at the desk by staff; lost books are charged at the copy's price. `php artisan library:daily` sends due and overdue reminders and releases holds not collected in time.
- **Health centre** (Health Centre). Allergies, conditions, medications, disabilities, fitness notes and clinical visit notes are encrypted in the database. Every time clinic staff open or change a record it is logged, and the person sees that log under *My Health*. Excuse notes show dates only, with a QR code to `/excuse/{token}` so a lecturer can check one. The clearance rule **medical_examined** passes once an examination is recorded. Statistics show counts only.
- **Accommodation** (Accommodation → Hostels, Allocation, Rules). Hostels have a gender, an audience and a fee per session; rooms are added in runs (e.g. block A, rooms 101–120, 4 beds). Students apply under *My Accommodation* with their choices; **Allocate beds** places waiting applicants in the order the rules set (order of application, new students first, final year first, or ballot). A bed in a paid hostel is reserved until the invoice is paid, confirmed automatically on payment, and released by `php artisan accommodation:daily` if not paid by the deadline. Moves, cancellations and warden check-in/out are on the Allocation page.
- **Teaching practice** (Teaching Practice → Exercises, Partner Schools). An exercise has its students (an audience), schools with places, supervisors per school, and a marking guide. Students choose schools if allowed; **Post waiting students** fills their choices first, then the schools with most places. Supervisors mark each visit under *TP Supervision*; the final score is the average of the visits. **Send scores** writes them to the chosen course's result sheet, split between CA and exam in the sheet's proportions, and the sheet then goes through the normal approval steps.

The three daily jobs (`library:daily`, `accommodation:daily`, with `leave:daily`) need the Laravel scheduler running (see above).

**Seeding.** `php artisan db:seed --force` runs every seeder in sections and prints a summary. Seeders listed as "always" (permissions, roles and the administrator) run every time so new permissions reach the live server; "once" seeders are remembered in the `seeder_log` table and skipped afterwards. Add new seeders to `DatabaseSeeder::sections()`.

## Online payments (step 7)

Students (under *My Fees*) and applicants (on their application page) can pay with **Paystack**, **Flutterwave** or **Remita**. Each is switched on under Fees & Payments → Payment Settings, with its keys stored encrypted; a key is never shown again, and leaving the box empty keeps the saved one. Nothing is on until someone enters keys, and offline payments recorded by the bursary always work.

- A payment only counts once the gateway itself confirms it was paid in full and in naira. The portal asks the gateway when the payer comes back (`/payments/return/{reference}`), when the gateway calls `/webhook/payments/{paystack|flutterwave|remita}`, and every ten minutes (`php artisan payments:reconcile`, needs the scheduler). Webhooks are only a hint: Paystack's signature and Flutterwave's secret hash are checked, then the payment is confirmed with the gateway's API.
- Instalments and payment windows apply online too: a payer must pay at least the instalment due and no more than the balance.
- Before a new attempt starts, earlier unfinished attempts on the same invoice are checked, so someone who paid but never came back is not charged twice.
- Remita gives each payment an RRR. Payers can finish online or pay the RRR at any bank; the notification or the scheduled check counts it. Each kind of fee can have its own Remita service type.
- Attempts not finished within the set number of hours (72 by default) are marked abandoned. If the money turns up later, it still counts.
- Staff can ask the gateway about a waiting payment from the invoice page (*Check with …*).
- Put the webhook URLs shown on the settings page into each gateway's dashboard. Test with the gateways' test keys (Paystack `sk_test_…`, Flutterwave `FLWSECK_TEST-…`, Remita in demo mode) before switching to live keys.

Another gateway is added by implementing `App\Services\Payments\PaymentGateway` and listing it in `config/payments.php`.

## The portal shell: sidebar, top bar and search

The layout follows the topclass-portal look. The sidebar is split into sections (My Portal, Academics, Bursary & Finance, Staff, Campus Life, Administration) with collapsible groups, highlights the page you are on, and ends with your name, role and a Sign Out button. The top bar has the search box, a light/dark switch (remembered per browser), notifications and your account menu.

The sidebar is built in one place, `App\Support\Navigation\Menu`; add a page there with its route and permission(s). The same menu feeds the search box, so people only ever see pages they may open.

The search box opens with **Ctrl+K** (⌘K on a Mac) or **/**. It finds pages as you type, then asks `GET /search` (`App\Services\Search\GlobalSearch`) for records: students (by name or matric number), applicants, staff, user accounts, courses, programmes, departments, invoices and payment references, library titles, venues and hostels. Each kind is searched only for people with the matching permission, and heads with a department or unit role only find their own students and staff. Recent searches are remembered in the browser.

## Page width, dropdowns that load on open, and connection alerts

- Every page uses the full content width (`container-fluid` with no max-width). Only printed documents (slips, receipts, payslips, the posting letter, transcripts) keep a paper width.
- A dropdown whose options come from another page (faculties, departments, programmes, courses, sessions, semesters, venues, hostels, leave types, salary structures ...) loads them from `GET /lookups/{list}` when it is hovered, focused or clicked, so something added in another tab shows up without reloading. Mark a select with `data-lookup="departments"`, optionally `data-lookup-parent="faculty_id"` (another field in the same form that narrows it) and `data-lookup-active="1"`. In a CRUD field definition use `'lookup' => 'departments', 'lookup_parent' => 'faculty_id', 'lookup_active' => true`. Lists live in `App\Services\Lookups\LookupService`; only reference data belongs there, never records about people.
- If the connection drops during an action (saving a form, opening a page, loading a dropdown), a dialog says which action is paused. When the connection is back, a second dialog says it can now continue: **Continue** runs it again (with the same idempotency key, so nothing is done twice), **Not now** drops it. Forms that submit the normal way are held the same way while offline. `Uv.whenOnline('what is paused')` gives the same behaviour to any script.

## Permission seeders

`PermissionSeeder` seeds every permission (`php artisan db:seed --class=PermissionSeeder`). It applies renames, seeds the core groups (dashboard, users, roles, permissions) and then calls one named seeder per module: `InstitutionPermissionSeeder`, `AcademicSetupPermissionSeeder`, `CourseRegistrationPermissionSeeder`, `ResultPermissionSeeder`, `AdmissionPermissionSeeder`, `FinancePermissionSeeder`, `VenueTimetablePermissionSeeder`, `StaffPermissionSeeder`, `PayrollPermissionSeeder`, `LeavePermissionSeeder`, `MessagingPermissionSeeder`, `PollPermissionSeeder`, `IdCardPermissionSeeder`, `LibraryPermissionSeeder`, `HealthPermissionSeeder`, `AccommodationPermissionSeeder` and `TeachingPracticePermissionSeeder`. Each one can also run on its own. The names, groups and descriptions live in `App\Support\PermissionCatalog` (which the role editor also reads); a test fails if a catalog group has no named seeder. `PermissionTableSeeder` is kept as an alias of `PermissionSeeder`.

## Mobile app API and push notifications

- The mobile app talks to `/api/v1` with a token from `POST /api/v1/auth/login` (Laravel Sanctum). Students sign in with a matric number or email. The full list of routes and the push payload is in [docs/MOBILE_API.md](docs/MOBILE_API.md).
- After pulling, run `composer install` (Sanctum was added) and `php artisan migrate` (personal access tokens, device tokens, `users.notify_push`).
- Every notice goes through `App\Services\Messenger`: the bell in the portal (also listed in the app), a push to each phone signed in to the app, and SMS/WhatsApp when switched on. New topics: results published, registration decisions, payments received, clearance, announcements. Messaging settings has a "Mobile app" column per topic and the Firebase setup (project id and service account key, stored encrypted). Each person can turn push off under Notification settings or in the app.
- Pushes are queued and sent by `php artisan messages:push`, scheduled every minute, so the scheduler (`php artisan schedule:work` on XAMPP, cron on the server) must run. Firebase tells us when an app was removed; that phone is then forgotten.
- Lecturers use the app to enter marks: `/teaching/courses`, open a course's sheet, save marks per assessment and submit (same rules as the portal).
- Student schedule logic lives in `App\Services\Schedule\MySchedule`, shared by the portal pages and the API.

## Sample data for development

On a development machine (`APP_ENV=local` or `development`), `php artisan db:seed` also runs `DemoDataSeeder`, which builds a whole sample university: 4 faculties, 11 departments and programmes with courses for 100 to 400 level, four sessions (2023/2024 to 2026/2027, the last one current), staff with HODs, deans, unit heads and officers (registrar, bursar, exams, HR, library, hostel, clinic) and their roles, about 260 students at every level with matric numbers, applicants waiting for a decision, course registrations and published results for every finished semester, school bills, invoices and payments (past sessions paid, this one a mix of paid, part-paid and unpaid), venues, hostels with bed allocations, a library with loans, and teaching-practice schools. It never runs on a live server.

It runs once (it is remembered in `seeder_log`); to start again use `php artisan migrate:fresh --seed`. It can also be run on its own with `php artisan db:seed --class=DemoDataSeeder`. At the end it prints one sample login per role. Every sample account uses the password `password`; staff emails end in `@staff.univite.test` student emails in `@student.univite.test` (the mobile app also accepts the matric number). No email is sent while seeding. The parts are `DemoAcademicSeeder`, `DemoStaffSeeder`, `DemoStudentSeeder`, `DemoAcademicRecordSeeder`, `DemoFeesSeeder` and `DemoCampusSeeder`, run together in one transaction.

## Code conventions

- **Dependency injection.** Services, reversal handlers, listeners and controllers receive their collaborators through the constructor (or a controller action's parameters). Nothing calls `app(...)` or `new SomeService()` inside them. The only exceptions are the two registries that pick a class from configuration: `App\Services\Payments\Gateways` and `App\Support\Reversals`. `tests/Feature/ConventionsTest.php` fails if this slips.
- **Breaking cycles.** When two services would need each other, one side raises an event instead. For example, paying a fee or finishing an onboarding step raises `StudentProgressChanged`, and the `RefreshClearance` listener re-checks clearance. Batch codes come from `MatricBatch::open()`.
- **DTOs.** Data that crosses a service boundary is a typed, `final readonly` class in `app/Data/<Area>/`. Inputs are built from validated requests with `fromArray()`. Outputs have a fixed shape, for example `Transcript`, `LeaveBalance`, `GraduationCheck`, `RegistrationContext`, `CreditLimits`, `BulkInvoiceResult`, `MatricRun`, `Admission`, `StatusCheck`, `ScoreImport` and the legacy-import report. Only objects that collect results as they go (`ParsedFile`, `BulkInvoicePreview`) are mutable.
- **Settings stay arrays.** Settings saved as JSON on a record, such as a timetable's configuration and seating rules, are still arrays.
