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
| Applicants: bio-data, guardian, programme, entry mode/level/session; admit or reject | Admissions & Students → Applicants | `View/Create/Edit/Delete applicants`, `Admit applicants`, `Reject applicants` |
| Students: created by admitting an applicant (matric number + login generated); profile, passport photo, status, password reset | Admissions & Students → Students | `View students`, `Edit students`, `Change student status`, `Reset student passwords` |
| Matric number format, e.g. `{YEAR}/{PROGRAMME_CODE}/{SEQ:4}` | Admissions & Students → Matric Number Format | `View matric number format`, `Set the matric number format` |
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
