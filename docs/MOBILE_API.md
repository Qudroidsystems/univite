# UniVite mobile app API (v1)

Base URL: `https://<portal>/api/v1`. Every response is JSON: `{"data": ..., "meta": {...}}` (`meta` only when there is something to say).
Errors: `401` not signed in, `403` not allowed (for example a staff account calling a student-only route, or a disabled account),
`404` not found or not yours, `422` validation (`{"message", "errors": {"field": ["..."]}}`), `429` too many attempts.

## Signing in

`POST /auth/login`

```json
{ "login": "AFU/2026/0001 or name@school.edu.ng", "password": "...", "device_name": "Pixel 8",
  "push_token": "<Firebase registration token, optional>", "platform": "android|ios", "app_version": "1.0.0" }
```

Returns `201` with `data.token` (send it as `Authorization: Bearer <token>` on every other call) and `data.user`.
Students can sign in with their matric number or email; staff with their email. Five wrong attempts for one account in a
minute are refused with `429`. Each sign-in has its own token; `POST /auth/logout` ends only that one and stops pushes
to that phone. Disabling an account in the portal ends all its tokens at once.

## Routes (all need the token)

| Method | Path | What it returns |
|---|---|---|
| GET | `/me` | The person: name, email, phone, avatar, roles, `type` (student or staff), student record, push choice, `teaches` (staff who can enter scores) |
| GET | `/dashboard` | Home screen: session, semester, unread count; for students CGPA, class, standing, fees outstanding, registration status, next class, next exam |
| GET | `/results` | Published results by semester: GPA per semester, CGPA, units, class, standing, each course's CA, exam, total, grade, and `assessments` (code, name, component, max, score) when the course was marked on an assessment scheme |
| GET | `/courses` | This semester's course form and its courses, whether registration is open (and why not), earlier forms, `register_url` |
| GET | `/timetable/lectures` | Weekly classes for the student's courses (day, time, course, venue, group) |
| GET | `/timetable/exams` | Papers with date, time, venues, and the student's hall and seat once seating is published; `meta.slip_url` |
| GET | `/timetable/teaching` | Staff only: classes, papers and invigilation duties |
| GET | `/fees` | Invoices with total, paid, outstanding and `pay_url`; `meta.outstanding` |
| GET | `/fees/{id}` | One invoice with its lines and payments (each with `receipt_url`) |
| GET | `/clearance` | Clearance items and their status; `meta.cleared`, `meta.matric_number` |
| GET | `/library` | Books on loan (due date, overdue) and holds |
| GET | `/accommodation` | Hostel place and application for the current session |
| GET | `/teaching/courses` | Staff only: courses assigned this semester (each with its score sheet once opened) and recent sheets |
| POST | `/teaching/courses/{course}/open` | Staff only: the score sheet for an assigned course this semester, created if needed |
| GET | `/teaching/sheets/{id}` | Staff only: a score sheet with its assessments and one row per student (see below) |
| PUT | `/teaching/sheets/{id}/scores` | Staff only: save marks; `meta.saved`, `meta.problems` |
| POST | `/teaching/sheets/{id}/submit` | Staff only: submit for approval (`422` with the reason when marks are missing) |
| GET | `/notifications?unread=1&page=1&per_page=20` | Notices (newest first) with `topic`, `screen`, `url`, `read_at`; `meta.unread` |
| GET | `/notifications/unread-count` | `{unread}` for the badge |
| POST | `/notifications/{id}/read` | Marks one as read |
| POST | `/notifications/read-all` | Marks all as read |
| GET | `/notifications/settings` | The person's push/SMS/WhatsApp choices and numbers, and which topics the school sends by each channel |
| PUT | `/notifications/settings` | Change any of `push`, `sms`, `whatsapp` (true/false), `phone_number`, `whatsapp_number` |
| POST | `/devices` | Register or refresh this phone's push token: `{token, platform, device_name, app_version}` |
| DELETE | `/devices` | Stop pushes to a token: `{token}` |

`GET /ping` needs no token and answers `{ok, app, time, institution: {name, short_name, motto, logo_url, color}}` (useful to
check the address and the connection; the app uses `institution` for its sign-in screen and colours).

Actions that need forms or payment pages (registering courses, paying, renewing books, applying for a hostel) open the
portal page given in the response (`pay_url`, `register_url`, `meta.url`) in the browser.

## Score sheets (lecturers)

A sheet has `mode`: `items` when an assessment scheme applies (one mark per assessment) or `ca_exam` (plain CA and exam
from Result Settings). `assessments` lists the columns: `{id, code, name, component (ca|exam), max}`; in `ca_exam` mode
they are `CA` and `EXAM` with `id: null`. Each row: `{student_id, matric_number, name, marks, ca, exam, total, grade, passed}`
where `marks` is keyed by assessment id (`items`) or by `CA` / `EXAM`. `editable` is true while the sheet is a draft and
this person may enter marks; `missing` counts students still lacking a mark. `status`: draft, submitted (waiting at
`stage`), approved, published.

Saving (only what is sent changes; `null` clears a mark):

```json
{ "scores": { "812": { "marks": { "41": 8.5, "42": 9, "44": 55 } } } }
{ "scores": { "812": { "ca": 24, "exam": 55 } } }
```

Each mark must be from 0 to its assessment's `max`; a student with a bad mark is skipped and named in `meta.problems`,
the rest are saved. The CA and exam are what the assessments add up to (null until every assessment of that part has a mark).

## Push notifications

Pushes go through Firebase Cloud Messaging. The school sets the Firebase project id and service account key under
**Messaging settings**; until then pushes are only written to the message log. Each push carries:

```json
"notification": { "title": "Your EDU101 result is out", "body": "Your result for EDU101 ... has been published." },
"data": { "topic": "results", "screen": "results", "notification_id": "<id in /notifications>", "url": "https://portal/my/results" }
```

`screen` is the app screen to open: `results`, `courses`, `fees`, `clearance`, `timetable`, `exams`, `library`,
`accommodation`, `id_card`, `payslips`, `leave`, `teaching_practice`, `health`, or `notifications`. Android
notifications use the channel id `portal`. When the app opens a notification it should call
`POST /notifications/{notification_id}/read`.

Topics that send notices today: results published, course registration approved or returned, payment received,
clearance and matric numbers, timetables published or changed, exam seat numbers, hostel places, library due dates,
ID cards, payslips, leave, teaching practice, health centre, and general announcements.
