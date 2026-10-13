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

## App settings from the school (`GET /ping` → `data.mobile`)

`allow_untrusted_certificate` (the school allows the app to connect while the website certificate cannot be verified;
the app then trusts this server's current certificate and refuses a different one later), `min_app_version`,
`store_url_android`, `store_url_ios`, `features` (chat, department_groups, course_groups, study_groups, feed, events,
marketplace, lost_found, materials, polls, id_card, lodging, directory, gpa_planner, reminders: true/false), `pusher`
(`{key, cluster}` when live chat is set up, else null), `market` (`max_photos`, `rules`, `needs_approval`), `chat` (`max_upload_mb`).
Edited on the website under **Campus Life → Mobile App**.

## Doing things in the app

| Method | Path | |
|---|---|---|
| GET/POST | `/registration` | Course form for this semester: `offered` courses (with `selected`, `blocked`, `carryover`), `limits`, `problems`, `blockers`. POST `{courses: [ids], submit: bool}` |
| GET | `/fees/{invoice}/pay` | `gateways`, `outstanding`, `minimum`, `blocker` |
| POST | `/fees/{invoice}/pay` | `{gateway, amount}` → `checkout {url, method, fields}`, `reference`, `return_prefix`. Open the checkout in a web view; when it reaches a URL starting with `return_prefix`, close it and call confirm |
| POST | `/payments/{reference}/confirm` | `status` (successful, failed, pending), `message`, `receipt_url` |
| GET | `/results/queries` · POST `/results/{result}/query` | The student's result queries · `{reason}` |
| GET | `/accommodation/options` · POST `/accommodation/apply` · POST `/accommodation/withdraw` | Hostels open to the student, whether applications are open · `{preferences: [hostel ids]}` |
| GET | `/library/search?q=` · POST `/library/loans/{loan}/renew` · POST `/library/items/{item}/hold` · POST `/library/holds/{hold}/cancel` | |
| POST | `/clearance/{item}/resubmit` | `{note}` |
| GET/POST | `/id-card` | The valid card with `qr` (the verification link security scans), a pending request, `problem`, `reasons` · POST `{reason, note}` |
| GET | `/polls` · `/polls/{id}` · POST `/polls/{id}/vote` | `{answers: {question id: option id, [option ids] or text}}` |
| PUT | `/me/password` | `{current_password, password, password_confirmation}`; other phones are signed out |

## Campus life

| Method | Path | |
|---|---|---|
| GET/PUT | `/me/profile` | `bio`, `share_lodging` (nobody, mates, everyone), `lodging_note` |
| POST/DELETE | `/me/photo` | Profile picture (multipart `photo`). `photo_url` appears on `/me` and wherever the person is shown |
| GET | `/chats` | The person's chats (department and course groups are kept up to date automatically) with `unread`; `meta.open_groups` are study groups they can join |
| POST | `/chats/direct` · `/chats/groups` | `{user_id}` · `{title, description, course_id (study group), open, members: [ids]}` |
| GET/PUT | `/chats/{id}` | `{muted, only_admins_post}` |
| GET/POST | `/chats/{id}/messages` | `?before=` older, `?after=` newer · multipart `body`, `file`, `reply_to` |
| POST | `/chats/{id}/read` · `/join` · `/leave` · GET/POST `/members` | |
| DELETE | `/chat/messages/{id}` · GET `/chat/attachments/{id}` | Files are served only to members (send the token) |
| POST | `/realtime/auth` | Pusher private-channel auth for `private-chat.{id}` |
| GET/POST | `/market` | `?section=market|lost_found&kind=&category=&q=&min_price=&max_price=&sort=` · multipart `kind, title, description, category, price, negotiable, condition, location, happened_on, photos[]` |
| GET | `/market/mine` · `/market/{id}` | |
| POST | `/market/{id}` · DELETE `/market/{id}` · POST `/market/{id}/status` · `/save` · `/contact` | Update (multipart, `remove_photos[]`) · `{status: active|sold|closed}` or `{bump: true}` · save for later · open a chat with the seller with the listing in it |
| GET/POST | `/feed` | `?only=events` · `{audience, audience_id, title, body, image, is_event, starts_at, ends_at, venue, pinned, allow_comments}`; `meta.can_post` lists where the person may post |
| GET/DELETE | `/feed/{id}` · POST `/feed/{id}/like` · `/comments` · `/rsvp` · DELETE `/feed/comments/{id}` | `{status: going|interested|none}` |
| GET | `/materials` · `/materials/courses/{course}` · POST `/materials/courses/{course}` · GET `/materials/{id}/download` · DELETE `/materials/{id}` | Lecturers upload (multipart `title, description, file` or `link`) |
| GET | `/people?q=&type=students|staff` · `/people/{id}` · POST `/people/{id}/block` | Profile cards; `lodging` only when the student shares it with the viewer |
| GET | `/reports/reasons` · POST `/reports` | `{type: message|listing|post|comment|user, id, reason, details}` |

Push `screen` values added: `chat` (with `conversation_id`), `marketplace` (`listing_id`), `feed` (`post_id`), `materials` (`course_id`).

## Academic companion

Switched on and off with the features `gpa_planner`, `reading_planner`, `campus_map`, `study_companion` and `health_reminders`
(Settings → Mobile App). `GET /ping` also returns `mobile.study` (`ai`: the AI tutor is ready, `running_ca`, `daily_limit`,
`upload_mb`) and `mobile.health_centre` (`name`, `phone`, `lat`, `lng`).

| Method | Path | |
|---|---|---|
| GET | `/planner/gpa` | Grading `scale` (letter, min, max, points, pass), `cgpa`, graded `units` and `points` so far, CGPA `method`, this semester's `courses` (units, `ca` so far when "show running CA" is on, `ca_max`, `exam_max`, `retake_of`) |
| POST | `/planner/gpa` | `{target, kind: gpa|cgpa, strengths: {course id: 1-5}, fixed: {course id: letter}}` → `feasible`, `needed_gpa`, `max_gpa`, `max_cgpa`, `message`, `scenarios: [{key: balanced|units|strengths|pass|best, label, note, gpa, cgpa, courses: [{grade, points, min_score, ca, exam_needed, exam_max}]}]` |
| GET | `/planner/reading` | The saved `plan`, `courses` (units, `lectures` a week, `exam_date`), `lectures` (kept free), `criteria` |
| POST | `/planner/reading` | `{free_time: [{day 1-7, start "HH:MM", end}], session_minutes, break_minutes, max_per_day, avoid_lectures, criteria: [units, timetable, priority, difficulty, exams, target], courses: {course id: {priority 1-5, difficulty 1-5, target_grade, skip}}}` → `sessions: [{day, starts_at, ends_at, course_id, code, title, kind: reading|revision|practice}]`, `summary` (minutes per course and why). DELETE removes it |
| GET | `/campus/places` | `venues: [{code, name, building, campus, entry, lat, lng}]` and `health_centre`. Lecture slots and exam papers also carry `location` / `locations` (and `seat.location`) |
| GET | `/study` | `ai_ready`, `remaining_today`, `documents`, recent `quizzes`, `progress`, course `materials` the student can study, `courses` |
| POST | `/study/documents` | multipart `file` (pdf, docx, pptx, txt, md, jpg, png, webp) or `text`, or `{material_id}`; `title`, `course_id`. Scanned PDFs and photos are read by the AI service |
| GET/DELETE | `/study/documents/{id}` · GET `/study/documents/{id}/sections/{n}` | Document with its `quizzes` and section `outline` · one section's text |
| POST | `/study/documents/{id}/quiz` | `{count 1-15, type: mcq|short|mixed, difficulty: easy|medium|hard|mixed, topic}` → quiz (answers hidden until answered; `?reveal=1` on GET shows them) |
| GET/DELETE | `/study/quizzes/{id}` · POST `/study/quizzes/{id}/answer` | `{index, answer}` (option index or text) → `correct`, `score` 0-1, `feedback`, `explanation`, `model_answer`, `quote`, `section`; `meta.score` percent |
| POST | `/study/ask` | `{question, document_id?, course_id?, history: [{role: user|assistant, content}]}` → `answer`, `from_material`, `sources: [{section, excerpt}]`, `follow_up`, `remaining` |
| GET | `/study/progress` | Practice results by `topics` and `courses`, weakest first |

Teaching: `GET /teaching/sheets/{id}/file?format=xlsx|csv` downloads the sheet to fill in offline (Excel by default: a column per assessment with its maximum, marks already entered, Excel refuses out-of-range marks); `POST /teaching/sheets/{id}/upload` (multipart `file`, .xlsx or .csv) reads it back → the sheet, `meta.read`, `meta.problems`. Each course in `GET /teaching/courses` now has `scheme` and `assessments` (the items it will be marked on) even before its sheet is opened.
Medication and appointment reminders never reach the portal: they are kept and scheduled on the phone.
