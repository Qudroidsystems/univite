<?php

use App\Messaging\Gateway;
use App\Models\Course;
use App\Models\CourseResult;
use App\Models\DeviceToken;
use App\Models\Invoice;
use App\Models\OutboundMessage;
use App\Models\RegistrationForm;
use App\Models\ResultSheet;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\Student;
use App\Services\Messenger;
use App\Services\RegistrationService;
use App\Services\ResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    campusWorld($this);
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $this->type = SemesterType::where('sort_order', 1)->first();
    $this->course = Course::create(['department_id' => $this->dept->id, 'code' => 'EDU101', 'title' => 'Foundations of Education', 'credit_units' => 2, 'level' => 100]);
    $this->student = makeStudent($this, 'Ada');
    $this->student->user->update(['password' => 'phone-pass-1']);
});

function apiLogin($test, string $login = null, array $extra = []): string
{
    return $test->postJson('/api/v1/auth/login', ['login' => $login ?? $test->student->user->email, 'password' => 'phone-pass-1', 'device_name' => 'Pixel 8'] + $extra)
        ->assertCreated()->json('data.token');
}

function publishedSheet($test): ResultSheet
{
    $sheet = ResultSheet::create(['course_id' => $test->course->id, 'academic_session_id' => $test->session->id, 'semester_type_id' => $test->type->id, 'status' => 'approved']);
    CourseResult::create(['result_sheet_id' => $sheet->id, 'student_id' => $test->student->id, 'course_id' => $test->course->id, 'academic_session_id' => $test->session->id,
        'semester_type_id' => $test->type->id, 'ca' => 30, 'exam' => 45, 'total' => 75, 'grade' => 'A', 'points' => 5, 'is_pass' => true, 'credit_units' => 2, 'status' => 'approved']);

    return app(ResultService::class)->publish($sheet, $test->admin);
}

it('signs in with an email or a matric number, refuses wrong or disabled accounts, and signs out', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['login' => $this->student->user->email, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('login');

    $token = apiLogin($this, $this->student->matric_number, ['push_token' => 'fcm-token-abc', 'platform' => 'android']);
    $this->withToken($token)->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.type', 'student')->assertJsonPath('data.student.matric_number', $this->student->matric_number)
        ->assertJsonPath('data.student.programme', 'B.Ed. Chemistry Education');
    expect(DeviceToken::where('user_id', $this->student->user_id)->count())->toBe(1);

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    expect(DeviceToken::count())->toBe(0)->and($this->student->user->tokens()->count())->toBe(0);

    $this->student->user->update(['is_disabled' => true]);
    $this->postJson('/api/v1/auth/login', ['login' => $this->student->user->email, 'password' => 'phone-pass-1'])->assertUnprocessable();
});

it('limits repeated sign-in attempts for one account', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', ['login' => 'someone@example.com', 'password' => 'guess-' . $i])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/login', ['login' => 'someone@example.com', 'password' => 'guess-6'])->assertTooManyRequests();
    apiLogin($this);   // another account from the same address still gets in
});

it('cuts off a token as soon as the account is disabled', function () {
    $token = apiLogin($this);
    $this->student->user->update(['is_disabled' => true]);
    $this->withToken($token)->getJson('/api/v1/dashboard')->assertForbidden();
});

it('tells the student on the web and on their phone when a result is published, and shows it in the app', function () {
    $token = apiLogin($this, null, ['push_token' => 'fcm-token-abc']);
    publishedSheet($this);

    $notice = $this->student->user->notifications()->first();
    expect($notice->data['topic'])->toBe('results')->and($notice->data['title'])->toBe('Your EDU101 result is out')
        ->and($notice->data['body'])->not->toContain(' A ');   // the grade stays in the portal

    $push = OutboundMessage::where('channel', 'push')->sole();
    expect($push->status)->toBe('queued')->and(json_decode($push->body, true)['data'])->toMatchArray(['topic' => 'results', 'screen' => 'results', 'notification_id' => $notice->id]);
    $this->artisan('messages:push')->assertSuccessful();
    expect($push->fresh()->status)->toBe('logged');   // no Firebase set up yet: written to the log only

    $this->withToken($token)->getJson('/api/v1/results')->assertOk()
        ->assertJsonPath('data.cgpa', 5)->assertJsonPath('data.semesters.0.courses.0.code', 'EDU101')->assertJsonPath('data.semesters.0.courses.0.grade', 'A');
    $this->withToken($token)->getJson('/api/v1/notifications')->assertOk()
        ->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.screen', 'results');
    $this->withToken($token)->postJson("/api/v1/notifications/{$notice->id}/read")->assertOk()->assertJsonPath('meta.unread', 0);
});

it('sends no push when the person turned push off, or the school switched it off for the topic', function () {
    $token = apiLogin($this, null, ['push_token' => 'fcm-token-abc']);
    $this->withToken($token)->putJson('/api/v1/notifications/settings', ['push' => false])->assertOk()->assertJsonPath('data.push', false);
    publishedSheet($this);
    expect(OutboundMessage::where('channel', 'push')->count())->toBe(0)->and($this->student->user->notifications()->count())->toBe(1);

    $this->student->user->update(['notify_push' => true]);
    \App\Models\Setting::put('messaging.topic.registration.push', '0');
    $form = RegistrationForm::create(['student_id' => $this->student->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'level' => 100, 'status' => 'submitted', 'total_units' => 2]);
    app(RegistrationService::class)->decide($form, true, $this->admin->id);
    expect(OutboundMessage::where('channel', 'push')->count())->toBe(0)
        ->and($this->student->user->notifications()->get()->pluck('data.title')->all())->toContain('Course registration approved');
});

it('serves the home screen, courses, timetables and fees, each only to the right person', function () {
    $token = apiLogin($this);
    RegistrationForm::create(['student_id' => $this->student->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'level' => 100, 'status' => 'approved', 'total_units' => 2])
        ->courses()->create(['student_id' => $this->student->id, 'course_id' => $this->course->id, 'credit_units' => 2]);
    $mine = Invoice::create(['kind' => 'fees', 'payable_type' => Student::class, 'payable_id' => $this->student->id, 'academic_session_id' => $this->session->id, 'title' => 'School fees', 'total' => 50000, 'amount_paid' => 20000, 'status' => 'part_paid', 'reference' => 'INV-2026-000101']);
    $other = makeStudent($this, 'Bola');
    $theirs = Invoice::create(['kind' => 'fees', 'payable_type' => Student::class, 'payable_id' => $other->id, 'academic_session_id' => $this->session->id, 'title' => 'School fees', 'total' => 50000, 'amount_paid' => 0, 'status' => 'unpaid', 'reference' => 'INV-2026-000102']);

    $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()
        ->assertJsonPath('data.fees_outstanding', 30000)->assertJsonPath('data.registration_status', 'approved')->assertJsonPath('data.session', '2026/2027');
    $this->withToken($token)->getJson('/api/v1/courses')->assertOk()
        ->assertJsonPath('data.current.status', 'approved')->assertJsonPath('data.current.courses.0.code', 'EDU101');
    $this->withToken($token)->getJson('/api/v1/timetable/lectures')->assertOk()->assertJsonPath('data', []);
    $this->withToken($token)->getJson('/api/v1/timetable/exams')->assertOk()->assertJsonPath('meta.clashes', 0);
    $this->withToken($token)->getJson('/api/v1/fees')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.outstanding', 30000);
    $this->withToken($token)->getJson("/api/v1/fees/{$mine->id}")->assertOk()->assertJsonPath('data.reference', 'INV-2026-000101');
    $this->withToken($token)->getJson("/api/v1/fees/{$theirs->id}")->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/timetable/teaching')->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/clearance')->assertOk();
    $this->withToken($token)->getJson('/api/v1/library')->assertOk();
    $this->withToken($token)->getJson('/api/v1/accommodation')->assertOk();

    $staff = makeStaff($this, 'Grace Lecturer');
    $staff->update(['password' => 'phone-pass-1']);
    $this->flushHeaders();
    $this->app['auth']->forgetGuards();   // a new "phone": forget the student signed in above
    $staffToken = apiLogin($this, $staff->email);
    $this->withToken($staffToken)->getJson('/api/v1/results')->assertForbidden();
    $this->withToken($staffToken)->getJson('/api/v1/timetable/teaching')->assertOk()->assertJsonStructure(['data' => ['classes', 'papers', 'duties']]);
    $this->withToken($staffToken)->getJson('/api/v1/dashboard')->assertOk()->assertJsonMissingPath('data.cgpa');
});

it('pushes through Firebase once it is set up, and forgets a phone the app was removed from', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    Gateway::put(['push_provider' => 'fcm', 'fcm_project_id' => 'univite-test', 'fcm_service_account' => json_encode(['client_email' => 'push@univite-test.iam.gserviceaccount.com', 'private_key' => $pem])]);
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/v1/projects/univite-test/messages:send' => Http::sequence()
            ->push(['name' => 'projects/univite-test/messages/1'])
            ->push(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);

    apiLogin($this, null, ['push_token' => 'phone-one']);
    apiLogin($this, null, ['push_token' => 'phone-two']);
    app(Messenger::class)->notify($this->student->user, 'announcements', 'Lectures resume Monday', 'All lectures resume on Monday 9 November.');
    expect(app(Messenger::class)->sendQueuedPushes())->toBe(1);

    expect(OutboundMessage::where('channel', 'push')->pluck('status')->sort()->values()->all())->toBe(['failed', 'sent'])
        ->and(DeviceToken::pluck('token')->all())->toBe(['phone-one']);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'messages:send') && $r->hasHeader('Authorization', 'Bearer ya29.test')
        && $r['message']['notification']['title'] === 'Lectures resume Monday' && $r['message']['data']['screen'] === 'notifications');
});

it('keeps push settings on the messaging page and the person\'s own choice on their notification settings', function () {
    $this->actingAs($this->admin)->get(route('messaging.settings'))->assertOk()->assertSee('Mobile app push notifications')->assertSee('Results published');
    $this->put(route('messaging.settings.update'), ['sms_provider' => 'log', 'whatsapp_provider' => 'log', 'country_code' => '234', 'push_provider' => 'fcm', 'fcm_project_id' => 'univite-app',
        'fcm_service_account' => '{"type":"service_account"}'])->assertSessionHas('error');
    expect(Gateway::provider('push'))->toBe('log');

    $this->actingAs($this->student->user)->get(route('notifications.preferences'))->assertOk()->assertSee('Send notifications to my phone');
});

it('answers ping with the school\'s name and colour for the app', function () {
    $this->getJson('/api/v1/ping')->assertOk()->assertJsonPath('data.ok', true)->assertJsonStructure(['data' => ['institution' => ['name', 'short_name', 'logo_url', 'color']]]);
});

it('lets a lecturer open a course in the app, enter a mark per assessment and submit', function () {
    $scheme = app(\App\Services\AssessmentService::class)->save(null, ['name' => 'Tests and exam'], [
        ['name' => 'Test', 'code' => 'T1', 'component' => 'ca', 'max_score' => 20],
        ['name' => 'Assignment', 'code' => 'ASG', 'component' => 'ca', 'max_score' => 10],
        ['name' => 'Exam', 'code' => 'EXAM', 'component' => 'exam', 'max_score' => 70],
    ], $this->admin);
    app(\App\Services\AssessmentService::class)->assign($scheme, 'university', null, null, $this->admin);
    RegistrationForm::create(['student_id' => $this->student->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'level' => 100, 'status' => 'approved', 'total_units' => 2])
        ->courses()->create(['student_id' => $this->student->id, 'course_id' => $this->course->id, 'credit_units' => 2]);
    \App\Models\ResultStage::query()->delete();

    $staff = makeStaff($this, 'Grace Lecturer');
    $staff->update(['password' => 'phone-pass-1']);
    $other = makeStaff($this, 'Other Lecturer');
    $other->update(['password' => 'phone-pass-1']);
    \App\Models\CourseAssignment::create(['user_id' => $staff->id, 'course_id' => $this->course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]);
    $token = apiLogin($this, $staff->email);

    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.teaches', true);
    $this->withToken($token)->getJson('/api/v1/teaching/courses')->assertOk()->assertJsonPath('data.courses.0.course.code', 'EDU101')->assertJsonPath('data.courses.0.sheet', null);
    $sheet = $this->withToken($token)->postJson("/api/v1/teaching/courses/{$this->course->id}/open")->assertOk()
        ->assertJsonPath('data.mode', 'items')->assertJsonPath('data.assessments.0.code', 'T1')->assertJsonPath('data.rows.0.matric_number', $this->student->matric_number)
        ->assertJsonPath('data.editable', true)->json('data');
    $ids = collect($sheet['assessments'])->pluck('id', 'code');

    // another lecturer cannot see or change it; a student cannot use the teaching routes
    $otherToken = apiLogin($this, $other->email);
    $this->app['auth']->forgetGuards();
    $this->withToken($otherToken)->getJson("/api/v1/teaching/sheets/{$sheet['id']}")->assertNotFound();
    $this->withToken($otherToken)->putJson("/api/v1/teaching/sheets/{$sheet['id']}/scores", ['scores' => []])->assertForbidden();
    $studentToken = apiLogin($this);
    $this->app['auth']->forgetGuards();
    $this->withToken($studentToken)->getJson('/api/v1/teaching/courses')->assertForbidden();
    $this->app['auth']->forgetGuards();

    $sid = (string) $this->student->id;
    $this->withToken($token)->putJson("/api/v1/teaching/sheets/{$sheet['id']}/scores", ['scores' => [$sid => ['marks' => [$ids['T1'] => 25]]]])
        ->assertOk()->assertJsonPath('meta.saved', 0)->assertJsonPath('meta.problems.0', fn ($p) => str_contains($p, 'Test must be a number from 0 to 20'));
    $this->withToken($token)->putJson("/api/v1/teaching/sheets/{$sheet['id']}/scores", ['scores' => [$sid => ['marks' => [$ids['T1'] => 15, $ids['ASG'] => 8]]]])
        ->assertOk()->assertJsonPath('meta.saved', 1)->assertJsonPath('data.rows.0.ca', 23)->assertJsonPath('data.missing', 1);
    $this->withToken($token)->postJson("/api/v1/teaching/sheets/{$sheet['id']}/submit")->assertStatus(422);
    $this->withToken($token)->putJson("/api/v1/teaching/sheets/{$sheet['id']}/scores", ['scores' => [$sid => ['marks' => [$ids['EXAM'] => 50]]]])
        ->assertOk()->assertJsonPath('data.rows.0.total', 73)->assertJsonPath('data.rows.0.grade', 'A');
    $this->withToken($token)->postJson("/api/v1/teaching/sheets/{$sheet['id']}/submit")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.editable', false);

    // once published, the student sees each assessment in the app
    app(ResultService::class)->publish(ResultSheet::find($sheet['id']), $this->admin);
    $this->app['auth']->forgetGuards();
    $this->withToken($studentToken)->getJson('/api/v1/results')->assertOk()
        ->assertJsonPath('data.semesters.0.courses.0.assessments.0.code', 'T1')
        ->assertJsonPath('data.semesters.0.courses.0.assessments.2.score', 50);
});
