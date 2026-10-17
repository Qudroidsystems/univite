<?php

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ContentReport;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseMaterial;
use App\Models\FeedPost;
use App\Models\MarketListing;
use App\Models\RegistrationForm;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\User;
use App\Support\MobileAppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    campusWorld($this);
    Storage::fake('public');
    Storage::fake('local');
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $this->type = SemesterType::where('sort_order', 1)->first();
    $this->course = Course::create(['department_id' => $this->dept->id, 'code' => 'EDU101', 'title' => 'Foundations of Education', 'credit_units' => 2, 'level' => 100]);
    $this->ada = makeStudent($this, 'Ada');
    $this->bola = makeStudent($this, 'Bola');
    $this->chem = makeStudent($this, 'Chidi', 'male', 100, $this->prog2);   // another department
    $this->lecturer = makeStaff($this, 'Grace Lecturer');
    CourseAssignment::create(['user_id' => $this->lecturer->id, 'course_id' => $this->course->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]);
    foreach ([$this->ada, $this->bola] as $s) {
        RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'level' => 100, 'status' => 'approved', 'total_units' => 2])
            ->courses()->create(['student_id' => $s->id, 'course_id' => $this->course->id, 'credit_units' => 2]);
    }
});

function as_(User $u)
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($u);

    return test();
}

it('puts people in their department and course groups automatically, and lets them chat', function () {
    as_($this->ada->user)->getJson('/api/v1/chats')->assertOk()->assertJsonCount(2, 'data');
    expect(ChatConversation::where('type', 'department')->where('department_id', $this->dept->id)->exists())->toBeTrue()
        ->and(ChatConversation::where('type', 'course')->where('course_id', $this->course->id)->exists())->toBeTrue();
    as_($this->lecturer)->getJson('/api/v1/chats')->assertOk();
    as_($this->bola->user)->getJson('/api/v1/chats')->assertOk();
    as_($this->chem->user)->getJson('/api/v1/chats')->assertOk()->assertJsonCount(1, 'data');   // only Chemistry's department group

    $course = ChatConversation::where('type', 'course')->first();
    as_($this->chem->user)->getJson("/api/v1/chats/{$course->id}/messages")->assertNotFound();

    // the lecturer posts in the course group; students see it unread and get a push
    $this->lecturer->deviceTokens()->create(['token' => 'x1', 'token_hash' => \App\Models\DeviceToken::hashOf('x1'), 'platform' => 'android']);
    $this->ada->user->deviceTokens()->create(['token' => 'a1', 'token_hash' => \App\Models\DeviceToken::hashOf('a1'), 'platform' => 'android']);
    as_($this->lecturer)->postJson("/api/v1/chats/{$course->id}/messages", ['body' => 'Test on Friday'])->assertCreated()->assertJsonPath('data.mine', true);
    as_($this->ada->user)->getJson('/api/v1/chats')->assertJsonPath('meta.unread', 1);
    expect(\App\Models\OutboundMessage::where('topic', 'chat')->where('user_id', $this->ada->user->id)->exists())->toBeTrue();

    // students' messages in a class group are not pushed (only lecturers' announcements)
    $before = \App\Models\OutboundMessage::count();
    $msgs = as_($this->ada->user)->getJson("/api/v1/chats/{$course->id}/messages")->assertOk()->json('data');
    as_($this->ada->user)->postJson("/api/v1/chats/{$course->id}/read", ['message_id' => end($msgs)['id']])->assertOk();
    as_($this->ada->user)->getJson('/api/v1/chats')->assertJsonPath('meta.unread', 0);
    as_($this->ada->user)->postJson("/api/v1/chats/{$course->id}/messages", ['body' => 'Which chapters?'])->assertCreated();
    expect(\App\Models\OutboundMessage::count())->toBe($before);

    // a photo in a direct chat, served only to its members
    $dm = as_($this->ada->user)->postJson('/api/v1/chats/direct', ['user_id' => $this->bola->user->id])->assertOk()->json('data');
    $sent = as_($this->ada->user)->post("/api/v1/chats/{$dm['id']}/messages", ['file' => UploadedFile::fake()->image('notes.jpg')], ['Accept' => 'application/json'])->assertCreated()->json('data');
    expect($sent['kind'])->toBe('image');
    as_($this->bola->user)->get($sent['attachment']['url'])->assertOk();
    as_($this->chem->user)->get($sent['attachment']['url'])->assertNotFound();

    // the lecturer makes the group announcement-only
    as_($this->lecturer)->putJson("/api/v1/chats/{$course->id}", ['only_admins_post' => true])->assertOk()->assertJsonPath('data.only_admins_post', true);
    as_($this->bola->user)->postJson("/api/v1/chats/{$course->id}/messages", ['body' => 'hi'])->assertStatus(422);
    as_($this->bola->user)->postJson("/api/v1/chats/{$course->id}/leave")->assertStatus(422);   // automatic groups cannot be left
});

it('blocks people both ways and lets anyone report', function () {
    $dm = as_($this->ada->user)->postJson('/api/v1/chats/direct', ['user_id' => $this->bola->user->id])->json('data');
    $m = as_($this->bola->user)->postJson("/api/v1/chats/{$dm['id']}/messages", ['body' => 'rude'])->json('data');
    as_($this->ada->user)->postJson('/api/v1/reports', ['type' => 'message', 'id' => $m['id'], 'reason' => 'abuse'])->assertCreated();
    as_($this->ada->user)->postJson("/api/v1/people/{$this->bola->user->id}/block", ['block' => true])->assertOk();
    as_($this->bola->user)->postJson("/api/v1/chats/{$dm['id']}/messages", ['body' => 'again'])->assertStatus(422);
    as_($this->bola->user)->postJson('/api/v1/chats/direct', ['user_id' => $this->ada->user->id])->assertStatus(422);

    $mod = makeStaff($this, 'Mod Erator', null, ['Handle reports']);
    $this->actingAs($mod)->get(route('campus.reports'))->assertOk()->assertSee('rude');
    $this->post(route('campus.reports.handle', ContentReport::first()->id), ['action' => 'remove'])->assertSessionHas('success');
    expect(ChatMessage::withTrashed()->find($m['id'])->trashed())->toBeTrue();
});

it('lets students start and join study groups only for courses they take', function () {
    $g = as_($this->ada->user)->postJson('/api/v1/chats/groups', ['title' => 'EDU101 revision', 'course_id' => $this->course->id, 'open' => true])->assertCreated()->json('data');
    as_($this->chem->user)->postJson('/api/v1/chats/groups', ['title' => 'Mine', 'course_id' => $this->course->id])->assertStatus(422);
    $open = as_($this->bola->user)->getJson('/api/v1/chats')->json('meta.open_groups');
    expect(collect($open)->pluck('id'))->toContain($g['id']);
    as_($this->bola->user)->postJson("/api/v1/chats/{$g['id']}/join")->assertOk();
    as_($this->chem->user)->postJson("/api/v1/chats/{$g['id']}/join")->assertStatus(422);
    as_($this->ada->user)->getJson("/api/v1/chats/{$g['id']}/members")->assertOk()->assertJsonCount(2, 'data');
});

it('runs the marketplace and lost & found, with approval, contact by chat and moderation', function () {
    app(MobileAppSettings::class)->save(['market_needs_approval' => '1']);
    $l = as_($this->ada->user)->post('/api/v1/market', ['kind' => 'sale', 'title' => 'Chemistry textbook', 'price' => 4500, 'category' => 'books', 'condition' => 'good',
        'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonCount(2, 'data.photos')->json('data');
    as_($this->bola->user)->getJson('/api/v1/market')->assertOk()->assertJsonCount(0, 'data');
    as_($this->bola->user)->getJson("/api/v1/market/{$l['id']}")->assertNotFound();

    $mod = makeStaff($this, 'Market Mod', null, ['Moderate the marketplace']);
    $this->actingAs($mod)->get(route('campus.market'))->assertOk()->assertSee('Chemistry textbook');
    $this->post(route('campus.market.moderate', $l['id']), ['decision' => 'approve'])->assertSessionHas('success');

    as_($this->bola->user)->getJson('/api/v1/market?q=chemistry')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.seller.name', $this->ada->user->name);
    as_($this->bola->user)->postJson("/api/v1/market/{$l['id']}/save")->assertJsonPath('data.saved', true);
    $chat = as_($this->bola->user)->postJson("/api/v1/market/{$l['id']}/contact", ['message' => 'Still available?'])->assertOk()->json('data');
    expect(ChatMessage::where('chat_conversation_id', $chat['id'])->where('kind', 'listing')->first()->meta['title'])->toBe('Chemistry textbook');

    as_($this->ada->user)->postJson("/api/v1/market/{$l['id']}/status", ['status' => 'sold'])->assertJsonPath('data.status', 'sold');
    as_($this->bola->user)->getJson('/api/v1/market')->assertJsonCount(0, 'data');

    // lost & found has no price and its own section; three reports hide a listing at once
    app(MobileAppSettings::class)->save(['market_needs_approval' => '0']);
    $lost = as_($this->ada->user)->postJson('/api/v1/market', ['kind' => 'lost', 'title' => 'Blue water bottle', 'price' => 100, 'location' => 'Library'])->assertCreated()->json('data');
    expect($lost['price'])->toBeNull();
    as_($this->bola->user)->getJson('/api/v1/market?section=lost_found')->assertJsonCount(1, 'data');
    foreach ([$this->bola, $this->chem] as $s) {
        as_($s->user)->postJson('/api/v1/reports', ['type' => 'listing', 'id' => $lost['id'], 'reason' => 'spam'])->assertCreated();
    }
    as_($this->lecturer)->postJson('/api/v1/reports', ['type' => 'listing', 'id' => $lost['id'], 'reason' => 'spam'])->assertCreated();
    expect(MarketListing::find($lost['id'])->status)->toBe('hidden');

    // switched off by the school
    app(MobileAppSettings::class)->save(['feature_marketplace' => '0']);
    as_($this->bola->user)->getJson('/api/v1/market')->assertForbidden();
});

it('shows feed posts to the right people, with likes, comments and RSVPs', function () {
    $poster = makeStaff($this, 'News Desk', null, ['Post to the campus feed']);
    as_($poster)->postJson('/api/v1/feed', ['audience' => 'department', 'audience_id' => $this->dept2->id, 'body' => 'Chemistry lab closed'])->assertCreated();
    $event = as_($poster)->postJson('/api/v1/feed', ['audience' => 'everyone', 'title' => 'Convocation', 'body' => 'Join us', 'is_event' => true,
        'starts_at' => now()->addDays(3)->toDateTimeString(), 'venue' => 'Main hall'])->assertCreated()->json('data');

    as_($this->ada->user)->getJson('/api/v1/feed')->assertOk()->assertJsonCount(1, 'data');
    as_($this->chem->user)->getJson('/api/v1/feed')->assertOk()->assertJsonCount(2, 'data');
    as_($this->ada->user)->getJson('/api/v1/feed?only=events')->assertJsonCount(1, 'data')->assertJsonPath('data.0.venue', 'Main hall');

    // ordinary staff post only to their department; students not unless allowed
    as_($this->lecturer)->postJson('/api/v1/feed', ['audience' => 'everyone', 'body' => 'x'])->assertStatus(422);
    as_($this->lecturer)->postJson('/api/v1/feed', ['audience' => 'department', 'audience_id' => $this->dept->id, 'body' => 'Office hours moved'])->assertCreated();
    as_($this->ada->user)->postJson('/api/v1/feed', ['audience' => 'department', 'audience_id' => $this->dept->id, 'body' => 'hi'])->assertStatus(422);

    as_($this->ada->user)->postJson("/api/v1/feed/{$event['id']}/like")->assertJsonPath('data.liked', true);
    as_($this->ada->user)->postJson("/api/v1/feed/{$event['id']}/comments", ['body' => 'Congrats!'])->assertCreated()->assertJsonPath('data.comment_list.0.body', 'Congrats!');
    as_($this->ada->user)->postJson("/api/v1/feed/{$event['id']}/rsvp", ['status' => 'going'])->assertJsonPath('data.going', 1)->assertJsonPath('data.my_rsvp', 'going');

    $this->actingAs($poster)->get(route('campus.feed'))->assertOk()->assertSee('Convocation');
});

it('shares course materials with the students of the course only', function () {
    $m = as_($this->lecturer)->post("/api/v1/materials/courses/{$this->course->id}", ['title' => 'Week 1 slides', 'file' => UploadedFile::fake()->create('w1.pdf', 300, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    as_($this->ada->user)->getJson('/api/v1/materials')->assertOk()->assertJsonPath('data.0.materials', 1);
    as_($this->ada->user)->getJson("/api/v1/materials/courses/{$this->course->id}")->assertOk()->assertJsonPath('data.0.title', 'Week 1 slides');
    as_($this->ada->user)->get($m['file']['url'])->assertOk();
    expect(CourseMaterial::first()->downloads)->toBe(1);
    as_($this->chem->user)->get($m['file']['url'])->assertNotFound();
    as_($this->ada->user)->postJson("/api/v1/materials/courses/{$this->course->id}", ['title' => 'mine', 'link' => 'https://example.com'])->assertStatus(422);

    $this->actingAs($this->lecturer)->get(route('campus.materials'))->assertOk()->assertSee('Week 1 slides');
});

it('shows where a student lives only to the people she chose, and her own profile picture', function () {
    $this->ada->user->update(['lodging_note' => 'Off campus, Unity Estate, house 4']);
    as_($this->bola->user)->getJson("/api/v1/people/{$this->ada->user->id}")->assertOk()->assertJsonPath('data.lodging', null);

    as_($this->ada->user)->putJson('/api/v1/me/profile', ['share_lodging' => 'mates', 'bio' => 'Chemistry nerd'])->assertOk();
    as_($this->bola->user)->getJson("/api/v1/people/{$this->ada->user->id}")->assertJsonPath('data.lodging.note', 'Off campus, Unity Estate, house 4')->assertJsonPath('data.bio', 'Chemistry nerd');
    as_($this->chem->user)->getJson("/api/v1/people/{$this->ada->user->id}")->assertJsonPath('data.lodging', null);   // not a mate

    as_($this->ada->user)->putJson('/api/v1/me/profile', ['share_lodging' => 'everyone'])->assertOk();
    as_($this->chem->user)->getJson("/api/v1/people/{$this->ada->user->id}")->assertJsonPath('data.lodging.note', 'Off campus, Unity Estate, house 4');

    $url = as_($this->ada->user)->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg')], ['Accept' => 'application/json'])->assertOk()->json('data.photo_url');
    expect($url)->toContain('avatars/');
    as_($this->bola->user)->getJson('/api/v1/people?q=Ada')->assertOk()->assertJsonPath('data.0.photo_url', $url);
    as_($this->ada->user)->getJson('/api/v1/me')->assertJsonPath('data.photo_url', $url);
});

it('sends the app its settings in ping, and lets an admin change them on the web', function () {
    $this->getJson('/api/v1/ping')->assertOk()->assertJsonPath('data.mobile.allow_untrusted_certificate', false)->assertJsonPath('data.mobile.features.chat', true)
        ->assertJsonPath('data.mobile.pusher', null);
    $this->actingAs($this->admin)->get(route('campus.settings'))->assertOk()->assertSee('security certificate');
    $this->put(route('campus.settings.save'), ['allow_untrusted_certificate' => '1', 'feature_marketplace' => '0', 'chat_max_upload_mb' => 8, 'study_group_max' => 60,
        'market_max_photos' => 5, 'market_listing_days' => 45, 'pusher_key' => 'k', 'pusher_app_id' => '1', 'pusher_secret' => 's', 'pusher_cluster' => 'eu'])->assertSessionHas('success');
    $this->getJson('/api/v1/ping')->assertJsonPath('data.mobile.allow_untrusted_certificate', true)->assertJsonPath('data.mobile.features.marketplace', false)
        ->assertJsonPath('data.mobile.pusher.key', 'k')->assertJsonMissingPath('data.mobile.pusher.secret');
});

it('lets students register courses, query results, vote and change their password in the app', function () {
    \App\Models\ProgrammeCourse::create(['programme_id' => $this->prog->id, 'course_id' => $this->course->id, 'level' => 100, 'semester_order' => 1, 'status' => 'compulsory']);
    $new = makeStudent($this, 'Dayo');
    $new->update(['needs_onboarding' => false]);
    $reg = as_($new->user)->getJson('/api/v1/registration')->assertOk()->json('data');
    expect($reg['term'])->not->toBeNull();
    if ($reg['blockers'] === []) {
        as_($new->user)->postJson('/api/v1/registration', ['courses' => [$this->course->id]])->assertOk()->assertJsonPath('data.offered.0.selected', true);
    }

    $new->user->update(['password' => 'old-password-1']);
    as_($new->user)->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertStatus(422);
    as_($new->user)->putJson('/api/v1/me/password', ['current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertOk();
    expect(\Illuminate\Support\Facades\Hash::check('new-password-1', $new->user->fresh()->password))->toBeTrue();

    as_($new->user)->getJson('/api/v1/polls')->assertOk();
    as_($new->user)->getJson('/api/v1/id-card')->assertOk()->assertJsonStructure(['data' => ['card', 'pending', 'problem', 'reasons']]);
    as_($new->user)->getJson('/api/v1/results/queries')->assertOk();
});
