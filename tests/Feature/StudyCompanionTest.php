<?php

use App\Models\AiUsage;
use App\Models\Course;
use App\Models\CourseResult;
use App\Models\GradeBand;
use App\Models\RegistrationForm;
use App\Models\ResultSheet;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\StudyDocument;
use App\Models\StudyPlan;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\TimetableItem;
use App\Models\Venue;
use App\Services\Study\ReadingPlanner;
use App\Services\Study\TextExtractor;
use App\Support\GradeScale;
use App\Support\MobileAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    campusWorld($this);
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $this->type = SemesterType::where('sort_order', 1)->first();
    GradeBand::query()->delete();
    foreach ([['A', 70, 100, 5, true], ['B', 60, 69.99, 4, true], ['C', 50, 59.99, 3, true], ['D', 45, 49.99, 2, true], ['E', 40, 44.99, 1, true], ['F', 0, 39.99, 0, false]] as [$l, $min, $max, $p, $pass]) {
        GradeBand::create(['letter' => $l, 'min_score' => $min, 'max_score' => $max, 'points' => $p, 'is_pass' => $pass]);
    }
    GradeScale::flush();

    $this->c1 = Course::create(['department_id' => $this->dept->id, 'code' => 'CHM101', 'title' => 'General Chemistry', 'credit_units' => 3, 'level' => 100]);
    $this->c2 = Course::create(['department_id' => $this->dept->id, 'code' => 'EDU101', 'title' => 'Foundations of Education', 'credit_units' => 2, 'level' => 100]);
    $this->c3 = Course::create(['department_id' => $this->dept->id, 'code' => 'GST101', 'title' => 'Use of English', 'credit_units' => 1, 'level' => 100]);
    $this->student = makeStudent($this, 'Ada');
    $this->student->user->update(['password' => 'phone-pass-1']);
    $form = RegistrationForm::create(['student_id' => $this->student->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'level' => 100, 'status' => 'approved', 'total_units' => 6]);
    foreach ([$this->c1, $this->c2, $this->c3] as $c) {
        $form->courses()->create(['student_id' => $this->student->id, 'course_id' => $c->id, 'credit_units' => $c->credit_units]);
    }
    $this->token = $this->postJson('/api/v1/auth/login', ['login' => $this->student->user->email, 'password' => 'phone-pass-1', 'device_name' => 'Pixel'])->json('data.token');
});

function aiOn(string $provider = 'anthropic'): void
{
    app(MobileAppSettings::class)->save(['ai_provider' => $provider, 'ai_api_key' => 'test-key', 'ai_daily_limit' => 5]);
}

/** A previous semester's published result, for the CGPA tests. */
function pastResult($test, Course $course, string $grade, float $points, int $units): void
{
    $old = \App\Models\AcademicSession::firstOrCreate(['name' => '2025/2026'], ['is_current' => false]);
    $sheet = ResultSheet::create(['course_id' => $course->id, 'academic_session_id' => $old->id, 'semester_type_id' => $test->type->id, 'status' => 'published']);
    CourseResult::create(['result_sheet_id' => $sheet->id, 'student_id' => $test->student->id, 'course_id' => $course->id, 'academic_session_id' => $old->id,
        'semester_type_id' => $test->type->id, 'ca' => 20, 'exam' => 30, 'total' => 50, 'grade' => $grade, 'points' => $points, 'is_pass' => $points > 0, 'credit_units' => $units, 'status' => 'published']);
}

it('shows the lecturer the assessments of each course before the sheet is opened', function () {
    $svc = app(\App\Services\AssessmentService::class);
    $scheme = $svc->save(null, ['name' => 'Two tests and exam'], [
        ['name' => 'Test 1', 'code' => 'T1', 'component' => 'ca', 'max_score' => 15],
        ['name' => 'Test 2', 'code' => 'T2', 'component' => 'ca', 'max_score' => 15],
        ['name' => 'Exam', 'code' => 'EXAM', 'component' => 'exam', 'max_score' => 70],
    ], $this->admin);
    $svc->assign($scheme, 'course', $this->c1->id, null, $this->admin);
    $staff = makeStaff($this, 'Grace Lecturer');
    $staff->update(['password' => 'phone-pass-1']);
    foreach ([$this->c1, $this->c2] as $c) {
        \App\Models\CourseAssignment::create(['user_id' => $staff->id, 'course_id' => $c->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]);
    }
    $this->app['auth']->forgetGuards();
    $token = $this->postJson('/api/v1/auth/login', ['login' => $staff->email, 'password' => 'phone-pass-1', 'device_name' => 'Pixel'])->json('data.token');

    $this->withToken($token)->getJson('/api/v1/teaching/courses')->assertOk()
        ->assertJsonPath('data.courses.0.course.code', 'CHM101')->assertJsonPath('data.courses.0.sheet', null)
        ->assertJsonPath('data.courses.0.scheme.name', 'Two tests and exam')
        ->assertJsonPath('data.courses.0.assessments.0.code', 'T1')->assertJsonPath('data.courses.0.assessments.2.max', 70)
        ->assertJsonPath('data.courses.1.scheme', null)->assertJsonPath('data.courses.1.assessments.0.code', 'CA')->assertJsonPath('data.courses.1.assessments.1.code', 'EXAM');

    // and on the portal's Results page
    $this->app['auth']->forgetGuards();
    $this->actingAs($staff)->get(route('results.sheets.index'))->assertOk()
        ->assertSee('Two tests and exam')->assertSee('Test 1 /15')->assertSee('Exam /70')->assertSee('No assessment scheme');
});

it('works out grade combinations for a target GPA and refuses an impossible one', function () {
    $ctx = $this->withToken($this->token)->getJson('/api/v1/planner/gpa')->assertOk()
        ->assertJsonPath('data.courses.0.code', 'CHM101')->assertJsonPath('data.courses.0.units', 3)->assertJsonPath('data.max_points', 5)->json('data');
    expect($ctx['courses'][0]['ca'])->toBeNull();   // running CA is private unless the school allows it

    $plan = $this->withToken($this->token)->postJson('/api/v1/planner/gpa', ['target' => 4.5, 'kind' => 'gpa'])->assertOk()
        ->assertJsonPath('data.feasible', true)->json('data');
    foreach ($plan['scenarios'] as $s) {
        expect($s['gpa'])->toBeGreaterThanOrEqual(4.5);
        $pts = collect($s['courses'])->sum(fn ($c) => $c['points'] * $c['units']);
        expect(round($pts / 6, 2))->toBe($s['gpa']);
    }
    // "Focus on high-unit courses" puts the A in the 3-unit course
    $units = collect($plan['scenarios'])->firstWhere('key', 'units');
    expect($units['courses'][0]['grade'])->toBe('A');

    // strengths: strongest course gets the top grade
    $plan = $this->withToken($this->token)->postJson('/api/v1/planner/gpa', ['target' => 4.0, 'kind' => 'gpa', 'strengths' => [$this->c2->id => 5, $this->c1->id => 1]])->assertOk()->json('data');
    $strong = collect($plan['scenarios'])->firstWhere('key', 'strengths');
    expect(collect($strong['courses'])->firstWhere('code', 'EDU101')['grade'])->toBe('A')->and($strong['gpa'])->toBeGreaterThanOrEqual(4.0);

    $this->withToken($this->token)->postJson('/api/v1/planner/gpa', ['target' => 6, 'kind' => 'gpa'])->assertUnprocessable();
    // a fixed grade (already known) can make the target impossible
    $this->withToken($this->token)->postJson('/api/v1/planner/gpa', ['target' => 4.9, 'kind' => 'gpa', 'fixed' => [$this->c1->id => 'C']])->assertOk()
        ->assertJsonPath('data.feasible', false)->assertJsonPath('data.max_gpa', 4)->assertJsonPath('data.scenarios.0.key', 'best');
    // any pass is enough
    $this->withToken($this->token)->postJson('/api/v1/planner/gpa', ['target' => 1, 'kind' => 'gpa'])->assertOk()->assertJsonPath('data.already', true);
});

it('offers the same planner on the portal for students', function () {
    $this->app['auth']->forgetGuards();
    $this->actingAs($this->student->user)->get(route('my.gpa-planner'))->assertOk()->assertSee('CHM101')->assertSee('Grading scale');
    $this->actingAs($this->student->user)->get(route('my.gpa-planner', ['target' => 4.5, 'kind' => 'gpa']))->assertOk()
        ->assertSee('Focus on high-unit courses')->assertSee('GPA 4.');
    $this->actingAs($this->admin)->get(route('my.gpa-planner'))->assertForbidden();
});

it('plans for a target CGPA from the record so far, counting a retake in place of the earlier attempt', function () {
    $old = Course::create(['department_id' => $this->dept->id, 'code' => 'BIO101', 'title' => 'Biology', 'credit_units' => 4, 'level' => 100]);
    pastResult($this, $old, 'C', 3, 4);      // 12 points over 4 units: CGPA 3.00
    $plan = $this->withToken($this->token)->postJson('/api/v1/planner/gpa', ['target' => 4.0, 'kind' => 'cgpa'])->assertOk()->json('data');
    // (12 + x) / (4 + 6) >= 4  =>  x >= 28 grade points over 6 units  =>  semester GPA 4.67
    expect($plan['needed_gpa'])->toBe(4.67)->and($plan['feasible'])->toBeTrue();
    foreach ($plan['scenarios'] as $s) {
        expect($s['cgpa'])->toBeGreaterThanOrEqual(4.0);
    }

    // now CHM101 is a retake of an F: with "latest attempt counts" the F leaves the CGPA
    pastResult($this, $this->c1, 'F', 0, 3);
    $ctx = $this->withToken($this->token)->getJson('/api/v1/planner/gpa')->assertOk()->json('data');
    expect($ctx['units'])->toBe(7)->and($ctx['courses'][0]['retake_of']['grade'])->toBe('F');
    $plan = $this->withToken($this->token)->postJson('/api/v1/planner/gpa', ['target' => 4.0, 'kind' => 'cgpa'])->assertOk()->json('data');
    expect($plan['needed_gpa'])->toBe(4.67);   // the F's 3 units are dropped, so the same as before
});

it('uses CA marks already entered to tell the exam score needed, when the school allows it', function () {
    app(MobileAppSettings::class)->save(['show_running_ca' => '1']);
    $sheet = ResultSheet::create(['course_id' => $this->c1->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'status' => 'draft']);
    CourseResult::create(['result_sheet_id' => $sheet->id, 'student_id' => $this->student->id, 'course_id' => $this->c1->id, 'academic_session_id' => $this->session->id,
        'semester_type_id' => $this->type->id, 'ca' => 10, 'credit_units' => 3, 'status' => 'draft']);
    $ctx = $this->withToken($this->token)->getJson('/api/v1/planner/gpa')->assertOk()->json('data');
    expect($ctx['courses'][0]['ca'])->toEqual(10);

    $plan = $this->withToken($this->token)->postJson('/api/v1/planner/gpa', ['target' => 4.5, 'kind' => 'gpa'])->assertOk()->json('data');
    $chm = collect($plan['scenarios'][0]['courses'])->firstWhere('code', 'CHM101');
    // the exam score needed is the grade's lowest score less the CA already earned
    expect($chm['exam_needed'] + $chm['ca'])->toBe($chm['min_score'])->and($chm['exam_needed'])->toBeLessThanOrEqual($chm['exam_max']);
});

it('builds a weekly reading plan in free time around lectures, weighted by the criteria chosen', function () {
    $planner = new ReadingPlanner();
    $courses = [
        ['id' => 1, 'code' => 'CHM101', 'title' => 'Chem', 'units' => 4, 'lectures' => 3, 'exam_date' => '2026-11-01', 'priority' => 5, 'difficulty' => 5],
        ['id' => 2, 'code' => 'EDU101', 'title' => 'Edu', 'units' => 2, 'lectures' => 1, 'exam_date' => null, 'priority' => 2, 'difficulty' => 2],
        ['id' => 3, 'code' => 'GST101', 'title' => 'GST', 'units' => 1, 'lectures' => 1, 'exam_date' => null, 'priority' => 1, 'difficulty' => 1],
    ];
    $prefs = ['free_time' => [['day' => 1, 'start' => '16:00', 'end' => '21:00'], ['day' => 3, 'start' => '16:00', 'end' => '21:00'], ['day' => 6, 'start' => '09:00', 'end' => '13:00']],
        'session_minutes' => 60, 'break_minutes' => 15, 'max_per_day' => 3, 'criteria' => ['units', 'timetable', 'priority', 'difficulty', 'exams']];
    $lectures = [['day' => 1, 'starts_at' => '16:00', 'ends_at' => '18:00']];   // Monday 16:00-18:00 is a lecture

    $r = $planner->build($courses, $prefs, $lectures, 5, '2026-10-20');
    $sessions = collect($r['sessions']);
    expect($sessions)->not->toBeEmpty();
    // nothing on Monday before 18:15 (the lecture plus a 15-minute gap)
    expect($sessions->where('day', 1)->every(fn ($s) => $s['starts_at'] >= '18:15'))->toBeTrue();
    // at most three a day, never the same course twice in a row on a day, everyone gets at least one
    foreach ($sessions->groupBy('day') as $day) {
        expect($day->count())->toBeLessThanOrEqual(3);
        $codes = $day->pluck('code')->all();
        for ($i = 1; $i < count($codes); $i++) {
            expect($codes[$i])->not->toBe($codes[$i - 1]);
        }
    }
    $by = collect($r['summary'])->keyBy('code');
    expect($by['CHM101']['sessions'])->toBeGreaterThan($by['GST101']['sessions'])->and($by['GST101']['sessions'])->toBeGreaterThanOrEqual(1);
    expect($by['CHM101']['reasons'])->toContain('4 credit units');
    // the exam is 12 days away: its sessions are revision
    expect($sessions->where('code', 'CHM101')->pluck('kind')->unique()->all())->toBe(['revision']);
});

it('saves the reading plan through the app and keeps lecture times free', function () {
    $tt = Timetable::create(['kind' => 'lecture', 'name' => 'Lectures', 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'status' => 'published', 'config' => []]);
    $venue = Venue::create(['code' => 'LT1', 'name' => 'Lecture Theatre 1', 'type' => 'lecture_hall', 'capacity' => 200, 'uses' => ['lecture'], 'latitude' => 7.8231, 'longitude' => 6.0687]);
    $item = TimetableItem::create(['timetable_id' => $tt->id, 'course_id' => $this->c1->id, 'duration' => 2]);
    TimetableEntry::create(['timetable_id' => $tt->id, 'timetable_item_id' => $item->id, 'course_id' => $this->c1->id, 'unit_key' => 'L1', 'day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00', 'slots' => ['2-10'], 'venue_id' => $venue->id]);

    $this->withToken($this->token)->getJson('/api/v1/timetable/lectures')->assertOk()->assertJsonPath('data.0.location.lat', 7.8231)->assertJsonPath('data.0.venue_name', 'Lecture Theatre 1');
    $show = $this->withToken($this->token)->getJson('/api/v1/planner/reading')->assertOk()->assertJsonPath('data.plan', null)->json('data');
    expect(collect($show['courses'])->firstWhere('code', 'CHM101')['lectures'])->toBe(1);

    $this->withToken($this->token)->postJson('/api/v1/planner/reading', [
        'free_time' => [['day' => 2, 'start' => '07:00', 'end' => '14:00']],
        'session_minutes' => 60, 'break_minutes' => 0, 'max_per_day' => 6, 'criteria' => ['units'],
        'courses' => [$this->c3->id => ['skip' => true]],
    ])->assertOk()->assertJsonPath('data.sessions.0.starts_at', '07:00');
    $plan = StudyPlan::firstOrFail();
    // the 10:00-12:00 lecture (with 15 minutes either side) stays free
    expect(collect($plan->sessions)->pluck('starts_at')->all())->toBe(['07:00', '08:00', '12:15'])
        ->and(collect($plan->sessions)->pluck('code')->unique()->sort()->values()->all())->toBe(['CHM101', 'EDU101']);

    $this->withToken($this->token)->postJson('/api/v1/planner/reading', ['free_time' => [['day' => 2, 'start' => '10:00', 'end' => '11:00']], 'session_minutes' => 60, 'criteria' => ['units']])
        ->assertUnprocessable();
    $this->withToken($this->token)->deleteJson('/api/v1/planner/reading')->assertOk();
    expect(StudyPlan::count())->toBe(0);
});

it('lists campus places with a map position, and lets the venue office set them on the web', function () {
    Venue::create(['code' => 'LT1', 'name' => 'Lecture Theatre 1', 'type' => 'lecture_hall', 'capacity' => 200, 'uses' => ['lecture'], 'latitude' => 7.8231, 'longitude' => 6.0687]);
    Venue::create(['code' => 'LT2', 'name' => 'No position yet', 'type' => 'lecture_hall', 'capacity' => 100, 'uses' => ['lecture']]);
    app(MobileAppSettings::class)->save(['health_centre_latitude' => '7.82', 'health_centre_longitude' => '6.07', 'health_centre_phone' => '0800']);
    $this->withToken($this->token)->getJson('/api/v1/campus/places')->assertOk()
        ->assertJsonCount(1, 'data.venues')->assertJsonPath('data.venues.0.code', 'LT1')->assertJsonPath('data.health_centre.lat', 7.82);
    $this->getJson('/api/v1/ping')->assertJsonPath('data.mobile.features.campus_map', true)->assertJsonPath('data.mobile.study.ai', false);

    app(MobileAppSettings::class)->save(['feature_campus_map' => '0']);
    $this->withToken($this->token)->getJson('/api/v1/campus/places')->assertForbidden();
});

it('reads text from Word, PowerPoint and PDF files', function () {
    $x = new TextExtractor();
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Osmosis is the movement of water</w:t></w:r></w:p><w:p><w:r><w:t>across a membrane.</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    expect($x->extract($tmp, 'docx'))->toBe("Osmosis is the movement of water\nacross a membrane.");

    $content = "BT /F1 12 Tf 72 720 Td (Photosynthesis converts light) Tj 0 -14 Td [(into chemical) -250 (energy.)] TJ ET";
    $stream = gzcompress($content);
    $pdf = "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n2 0 obj << /Length " . strlen($stream) . " /Filter /FlateDecode >>\nstream\n{$stream}\nendstream\nendobj\n%%EOF";
    expect($x->pdf($pdf))->toContain('Photosynthesis converts light')->toContain('into chemical energy.');
});

it('studies with the AI tutor: upload notes, make a quiz, mark answers and answer questions from the material', function () {
    Storage::fake('local');
    $this->withToken($this->token)->getJson('/api/v1/study')->assertOk()->assertJsonPath('data.ai_ready', false);
    aiOn('anthropic');

    $notes = str_repeat("Osmosis is the net movement of water molecules through a selectively permeable membrane from a region of lower solute concentration to higher solute concentration. ", 6)
        . "\n\n" . str_repeat("Diffusion is the movement of particles from high to low concentration until equilibrium is reached. ", 6);
    $doc = $this->withToken($this->token)->post('/api/v1/study/documents', [
        'file' => UploadedFile::fake()->createWithContent('Biology notes.txt', $notes), 'course_id' => $this->c1->id,
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.title', 'Biology notes')->json('data');

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['title' => 'Osmosis check', 'questions' => [
                ['type' => 'mcq', 'question' => 'Osmosis moves which molecules?', 'options' => ['Salt', 'Water', 'Sugar', 'Oxygen'], 'answer' => 1, 'explanation' => 'Water moves.', 'topic' => 'Osmosis', 'section' => 1, 'quote' => 'net movement of water'],
                ['type' => 'short', 'question' => 'Define diffusion.', 'answer' => 'Movement of particles from high to low concentration.', 'explanation' => 'From the notes.', 'topic' => 'Diffusion', 'section' => 2],
            ]])]], 'usage' => ['input_tokens' => 900, 'output_tokens' => 300]])
            ->push(['content' => [['type' => 'text', 'text' => '```json {"score": 0.5, "feedback": "You said particles move, but not the direction."} ```']], 'usage' => ['input_tokens' => 100, 'output_tokens' => 30]])
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['answer' => 'Water moves towards the higher solute concentration.', 'from_material' => true, 'sections' => [1], 'follow_up' => 'What is a selectively permeable membrane?'])]], 'usage' => ['input_tokens' => 400, 'output_tokens' => 50]]),
    ]);

    $quiz = $this->withToken($this->token)->postJson("/api/v1/study/documents/{$doc['id']}/quiz", ['count' => 2, 'type' => 'mixed'])->assertCreated()
        ->assertJsonPath('data.title', 'Osmosis check')->assertJsonPath('data.questions.0.options.1', 'Water')
        ->assertJsonPath('data.questions.0.model_answer', null)->assertJsonPath('data.questions.0.correct_index', null)->json('data');

    $this->withToken($this->token)->postJson("/api/v1/study/quizzes/{$quiz['id']}/answer", ['index' => 0, 'answer' => 2])->assertOk()
        ->assertJsonPath('data.correct', false)->assertJsonPath('data.model_answer', 'Water')->assertJsonPath('meta.score', 0);
    $this->withToken($this->token)->postJson("/api/v1/study/quizzes/{$quiz['id']}/answer", ['index' => 1, 'answer' => 'Particles move.'])->assertOk()
        ->assertJsonPath('data.score', 0.5)->assertJsonPath('data.feedback', 'You said particles move, but not the direction.')->assertJsonPath('meta.score', 25);

    $this->withToken($this->token)->postJson('/api/v1/study/ask', ['question' => 'Which way does water move in osmosis?', 'document_id' => $doc['id']])->assertOk()
        ->assertJsonPath('data.from_material', true)->assertJsonPath('data.sources.0.section', 1)->assertJsonPath('data.remaining', 2);

    Http::assertSent(fn ($r) => $r->url() === 'https://api.anthropic.com/v1/messages' && $r->header('x-api-key')[0] === 'test-key' && str_contains($r['messages'][0]['content'], '[Section 1]'));
    expect(AiUsage::first()->requests)->toBe(3)->and(AiUsage::first()->input_tokens)->toBe(1400);

    $this->withToken($this->token)->getJson('/api/v1/study/progress')->assertOk()->assertJsonPath('data.topics.0.topic', 'Osmosis')->assertJsonPath('data.topics.0.percent', 0);

    // another student cannot see it
    $other = makeStudent($this, 'Bola');
    $other->user->update(['password' => 'phone-pass-1']);
    $this->app['auth']->forgetGuards();
    $t2 = $this->postJson('/api/v1/auth/login', ['login' => $other->user->email, 'password' => 'phone-pass-1', 'device_name' => 'x'])->json('data.token');
    $this->app['auth']->forgetGuards();
    $this->withToken($t2)->getJson("/api/v1/study/documents/{$doc['id']}")->assertNotFound();
    $this->withToken($t2)->getJson("/api/v1/study/quizzes/{$quiz['id']}")->assertNotFound();
});

it('stops at the daily AI allowance and explains a refused key', function () {
    aiOn('openai');
    $doc = StudyDocument::create(['user_id' => $this->student->user_id, 'title' => 'Notes', 'text' => str_repeat('Cells are the basic unit of life. ', 30), 'chars' => 990, 'status' => 'ready']);
    Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"answer": "Cells.", "from_material": false, "sections": []}']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]])]);
    foreach (range(1, 5) as $i) {
        $this->withToken($this->token)->postJson('/api/v1/study/ask', ['question' => 'What is a cell?', 'document_id' => $doc->id])->assertOk()->assertJsonPath('data.from_material', false);
    }
    $this->withToken($this->token)->postJson('/api/v1/study/ask', ['question' => 'Again?'])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'allowance'));
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer test-key') && $r['messages'][0]['role'] === 'system');

    AiUsage::query()->delete();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 403)]);
    aiOn('gemini');
    $this->withToken($this->token)->postJson('/api/v1/study/ask', ['question' => 'What is a cell?'])->assertUnprocessable()
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'AI key was refused'));
});
