<?php

use App\Data\Leave\LeaveApplication;
use App\Models\Department;
use App\Models\DocumentEmail;
use App\Models\Faculty;
use App\Models\Holiday;
use App\Models\LeaveDocument;
use App\Models\LeaveEntitlement;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OutboundMessage;
use App\Models\Setting;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\LeaveService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2027-03-01 08:00:00'); // a Monday
    Storage::fake('local');
    Holiday::flush(); // the holiday list is remembered for the request; start each test clean
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $person = fn ($name, $extra = []) => User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@example.com', 'password' => 'secret-pass', 'phone_number' => '0803' . random_int(1000000, 9999999)] + $extra);

    $this->dean = $person('Dean Ade');
    $this->hod = $person('Hod Bola');
    $this->hr = $person('Hr Chika');
    $this->registrar = $person('Registrar Dayo');
    $this->staff = $person('Staff Efe', ['gender' => 'female']);
    $this->colleague = $person('Colleague Femi', ['gender' => 'male']);
    foreach (['HR Officer' => $this->hr, 'Registrar' => $this->registrar] as $role => $u) {
        Role::findOrCreate($role, 'web');
        $u->assignRole($role);
    }
    $this->hr->givePermissionTo(['View leave applications', 'View confidential leave documents', 'Record staff resumption']);

    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU', 'dean_id' => $this->dean->id]);
    $this->dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Guidance and Counselling', 'code' => 'GCE', 'hod_id' => $this->hod->id]);
    foreach ([$this->hod, $this->staff, $this->colleague] as $u) {
        StaffProfile::create(['user_id' => $u->id, 'department_id' => $this->dept->id, 'category' => 'academic', 'grade_level' => 12, 'first_appointment_date' => '2019-01-10']);
    }
    $this->annual = LeaveType::where('code', 'annual')->first();
    $this->leave = app(LeaveService::class);
});

afterEach(fn () => Carbon::setTestNow());

function applyFor($test, array $over = [], array $files = []): LeaveRequest
{
    return $test->leave->apply($test->staff, LeaveApplication::fromArray(array_merge([
        'leave_type_id' => $test->annual->id, 'start_date' => '2027-03-15', 'end_date' => '2027-03-19', 'reason' => 'Family visit', 'acting_officer_id' => $test->colleague->id,
    ], $over), $files));
}

it('counts working days around weekends and holidays and finds the resumption day', function () {
    Holiday::create(['date' => '2027-03-17', 'name' => 'Founders Day']);
    expect($this->leave->countDays($this->annual, Carbon::parse('2027-03-12'), Carbon::parse('2027-03-22')))->toBe(6) // Fri, Mon, Tue, Thu, Fri, Mon
        ->and(LeaveService::nextWorkingDay(Carbon::parse('2027-03-19'))->toDateString())->toBe('2027-03-22')
        ->and($this->leave->countDays(LeaveType::where('code', 'maternity')->first(), Carbon::parse('2027-03-01'), Carbon::parse('2027-03-31')))->toBe(31);
});

it('gives the most specific entitlement rule and works out the balance', function () {
    LeaveEntitlement::create(['leave_type_id' => $this->annual->id, 'category' => 'academic', 'min_years' => 0, 'days' => 30]);
    LeaveEntitlement::create(['leave_type_id' => $this->annual->id, 'category' => 'academic', 'min_grade' => 12, 'min_years' => 5, 'days' => 36]);
    expect($this->leave->entitlement($this->staff, $this->annual))->toBe(36);
    $this->staff->staffProfile->update(['first_appointment_date' => '2025-01-10']);
    expect($this->leave->entitlement($this->staff->fresh(), $this->annual))->toBe(30);
});

it('refuses applications that break the leave rules, with the reason', function () {
    $assess = fn ($code, $from, $to, $acting = null, $docs = 0, $who = null) => $this->leave->assess($who ?? $this->staff, LeaveType::where('code', $code)->first(), Carbon::parse($from), Carbon::parse($to), $acting, $docs)->errors;

    expect(implode(' ', $assess('annual', '2027-03-15', '2027-06-30', $this->colleague->id)))->toContain('have 30 day(s) of Annual leave left');
    expect(implode(' ', $assess('annual', '2027-03-15', '2027-03-19')))->toContain('Name an acting officer');
    expect(implode(' ', $assess('annual', '2027-03-15', '2027-03-19', $this->staff->id)))->toContain('your own acting officer');
    expect(implode(' ', $assess('sick', '2027-03-02', '2027-03-03')))->toContain('Attach the Medical certificate');
    expect(implode(' ', $assess('paternity', '2027-03-15', '2027-03-19')))->toContain('only for male staff');
    expect(implode(' ', $assess('study', '2027-03-15', '2027-09-15', $this->colleague->id)))->toContain('at least 30 days before');
    expect(implode(' ', $assess('casual', '2027-03-06', '2027-03-07')))->toContain('no working days');

    applyFor($this);
    expect(implode(' ', $assess('casual', '2027-03-18', '2027-03-18')))->toContain('overlap');
});

it('takes an application through HOD, HR and Registrar, telling everyone and sending the letters', function () {
    Setting::put('messaging.topic.leave.sms', '1');
    $this->actingAs($this->staff)->post(route('leave.store'), [
        'leave_type_id' => $this->annual->id, 'start_date' => '2027-03-15', 'end_date' => '2027-03-19', 'acting_officer_id' => $this->colleague->id, 'reason' => 'Rest',
    ])->assertRedirect();
    $r = LeaveRequest::firstOrFail();
    expect($r->reference)->toBe('LV/2027/00001')->and($r->days)->toBe(5)->and($r->expected_resumption->toDateString())->toBe('2027-03-22')
        ->and($r->steps()->pluck('label')->all())->toBe(['Head of department', 'Human resources', 'Registrar'])
        ->and($r->currentStep()?->label ?? $r->fresh()->steps->firstWhere('status', 'pending')->label)->toBe('Head of department');

    // Acknowledged by email and text; the HOD and the acting officer are told.
    expect(DocumentEmail::where('type', 'leave_letter')->where('recipient', $this->staff->email)->count())->toBe(1)
        ->and(OutboundMessage::where('user_id', $this->staff->id)->where('channel', 'sms')->value('status'))->toBe('logged')
        ->and($this->hod->notifications()->count())->toBe(1)
        ->and($this->colleague->notifications()->count())->toBe(1);

    // Only the current approver can decide; the applicant never can.
    expect($this->leave->canAct($this->hr, $r))->toBeFalse()->and($this->leave->canAct($this->staff, $r))->toBeFalse();
    $this->actingAs($this->hod)->get(route('leave.approvals'))->assertOk()->assertSee('Staff Efe');
    $this->actingAs($this->hr)->post(route('leave.decide', $r), ['action' => 'approve'])->assertSessionHas('error');
    $this->actingAs($this->hod)->post(route('leave.decide', $r), ['action' => 'approve', 'comment' => 'Fine by me'])->assertSessionHas('success');
    $this->actingAs($this->hr)->post(route('leave.decide', $r), ['action' => 'approve'])->assertSessionHas('success');
    expect($r->fresh()->status)->toBe('pending');
    $this->actingAs($this->registrar)->post(route('leave.decide', $r), ['action' => 'approve'])->assertSessionHas('success');

    $r->refresh();
    expect($r->status)->toBe('approved')
        ->and($this->leave->balance($this->staff, $this->annual, 2027)->taken)->toBe(5)
        ->and(DocumentEmail::where('type', 'leave_letter')->where('recipient', $this->staff->email)->count())->toBe(2)
        ->and($this->staff->notifications()->where('data->title', 'Leave approved')->exists())->toBeTrue();
    $this->actingAs($this->staff)->get(route('leave.letter', [$r, 'approval']))->assertOk()->assertSee('LV/2027/00001')->assertSee('Monday 22 March 2027');
    $this->actingAs($this->colleague)->post(route('leave.acting', $r), ['accept' => 1])->assertSessionHas('success');
    expect($r->fresh()->acting_status)->toBe('accepted');
});

it('returns an application for correction, then accepts the corrected one; a refusal needs a reason', function () {
    $r = applyFor($this);
    expect(fn () => $this->leave->decide($r, $this->hod, 'reject'))->toThrow(RuntimeException::class, 'Give a reason');
    $this->leave->decide($r, $this->hod, 'return', 'Start a week later please');
    expect($r->fresh()->status)->toBe('returned');

    $this->actingAs($this->staff)->get(route('leave.edit', $r))->assertOk()->assertSee('Start a week later');
    $this->actingAs($this->staff)->put(route('leave.update', $r), ['leave_type_id' => $this->annual->id, 'start_date' => '2027-03-22', 'end_date' => '2027-03-26', 'acting_officer_id' => $this->colleague->id])->assertRedirect(route('leave.show', $r));
    $r->refresh();
    expect($r->status)->toBe('pending')->and($r->reference)->toBe('LV/2027/00001')->and($r->start_date->toDateString())->toBe('2027-03-22')
        ->and($r->steps()->where('status', 'pending')->value('label'))->toBe('Head of department');
});

it('skips a step nobody holds, and sends it to the leave administrators when nobody on the route exists', function () {
    $this->dept->update(['hod_id' => null]);
    $r = applyFor($this);
    expect($r->steps->firstWhere('label', 'Head of department')->status)->toBe('skipped')
        ->and($r->steps->firstWhere('status', 'pending')->label)->toBe('Human resources');

    $this->hr->removeRole('HR Officer');
    $this->registrar->removeRole('Registrar');
    $other = applyFor($this, ['start_date' => '2027-04-05', 'end_date' => '2027-04-06']);
    expect($other->steps->firstWhere('status', 'pending')->label)->toBe('Leave administrator')
        ->and($this->leave->canAct($this->admin, $other))->toBeTrue();
});

it('keeps a medical certificate away from approvers who may not see it', function () {
    $r = applyFor($this, ['leave_type_id' => LeaveType::where('code', 'sick')->value('id'), 'start_date' => '2027-03-02', 'end_date' => '2027-03-03', 'acting_officer_id' => null], [UploadedFile::fake()->create('certificate.pdf', 20, 'application/pdf')]);
    $doc = LeaveDocument::firstOrFail();
    expect($doc->confidential)->toBeTrue();

    $this->actingAs($this->hod)->get(route('leave.show', $r))->assertOk()->assertSee('confidential')->assertDontSee('certificate.pdf');
    $this->actingAs($this->hod)->get(route('leave.document', $doc))->assertForbidden();
    $this->actingAs($this->hr)->get(route('leave.document', $doc))->assertOk();
    $this->actingAs($this->staff)->get(route('leave.document', $doc))->assertOk();
    $this->actingAs($this->colleague)->get(route('leave.show', $r))->assertForbidden();
});

it('warns when too much of a department would be away at once', function () {
    $this->leave->decide(applyFor($this, ['start_date' => '2027-03-15', 'end_date' => '2027-03-19']), $this->hod, 'approve');
    $hodLeave = $this->leave->assess($this->colleague, $this->annual, Carbon::parse('2027-03-16'), Carbon::parse('2027-03-17'), $this->hod->id);
    expect(implode(' ', $hodLeave->warnings))->toContain('2 of 3 staff in Guidance and Counselling would be away');
});

it('reminds approvers, escalates overdue decisions, counts down the days left and flags a late return, each once', function () {
    $r = applyFor($this);
    Carbon::setTestNow('2027-03-05 07:00:00');
    $sent = $this->leave->daily();
    expect($sent['approvals'])->toBe(1)->and($this->hod->notifications()->where('data->title', 'Reminder: leave waiting for you')->count())->toBe(1);
    Carbon::setTestNow('2027-03-08 07:00:00');
    expect($this->leave->daily()['escalated'])->toBe(1)
        ->and($this->admin->notifications()->where('data->title', 'Escalated: leave decision overdue')->exists())->toBeTrue();
    expect($this->leave->daily())->toBe(['approvals' => 0, 'escalated' => 0, 'starting' => 0, 'remaining' => 0, 'late' => 0]);

    foreach ([$this->hod, $this->hr, $this->registrar] as $u) {
        $this->leave->decide($r->fresh(), $u, 'approve');
    }
    Carbon::setTestNow('2027-03-12 07:00:00');
    expect($this->leave->daily()['starting'])->toBe(1);
    Carbon::setTestNow('2027-03-19 07:00:00'); // the last day: one working day left
    expect($this->leave->daily()['remaining'])->toBe(1)
        ->and($this->staff->notifications()->where('data->title', '1 working day(s) of leave left')->exists())->toBeTrue();
    Carbon::setTestNow('2027-03-24 07:00:00');
    expect($this->leave->daily()['late'])->toBe(1)->and($this->leave->daily()['late'])->toBe(0);

    // Back two working days late: recorded on the application and the resumption letter.
    $this->actingAs($this->staff)->get(route('leave.index'))->assertOk()->assertSee('Due back');
    $this->actingAs($this->staff)->post(route('leave.resume', $r), ['resumed_on' => '2027-03-24'])->assertSessionHas('success');
    expect($r->fresh()->late_days)->toBe(2)->and($r->fresh()->phase)->toBe('Resumed 2 working day(s) late');
});

it('gives back unused days when someone resumes early, and carries leftover days into the next year up to the limit', function () {
    $r = applyFor($this);
    foreach ([$this->hod, $this->hr, $this->registrar] as $u) {
        $this->leave->decide($r->fresh(), $u, 'approve');
    }
    Carbon::setTestNow('2027-03-17 09:00:00');
    $this->leave->recordResumption($r->fresh(), Carbon::parse('2027-03-17'), $this->hr);
    expect($r->fresh()->days_used)->toBe(2)->and($this->leave->balance($this->staff, $this->annual, 2027)->available())->toEqual(28);

    $this->leave->adjust($this->staff, $this->annual, 2027, -3, 'Unrecorded absence in January', $this->admin);
    expect($this->leave->balance($this->staff, $this->annual, 2027)->available())->toEqual(25);
    expect($this->leave->carryOver(2027, $this->admin))->toBeGreaterThan(0);
    expect($this->leave->balance($this->staff, $this->annual, 2028)->carried)->toEqual(10); // annual leave carries at most 10
    $this->leave->carryOver(2027, $this->admin);
    expect($this->leave->balance($this->staff, $this->annual, 2028)->carried)->toEqual(10);
});

it('opens every leave, messaging and notification page', function () {
    $r = applyFor($this);
    $this->actingAs($this->staff);
    foreach ([route('leave.index'), route('leave.create'), route('leave.show', $r), route('leave.calendar', ['month' => '2027-03', 'pending' => 1]), route('leave.letter', [$r, 'acknowledgement']),
        route('notifications.index'), route('notifications.preferences')] as $url) {
        $this->get($url)->assertOk();
    }
    $this->getJson(route('leave.check', ['leave_type_id' => $this->annual->id, 'start_date' => '2027-04-05', 'end_date' => '2027-04-09']))
        ->assertOk()->assertJson(['days' => 5, 'resume' => 'Monday 12 Apr 2027']);
    $this->get(route('leave.register'))->assertForbidden();

    $this->actingAs($this->admin);
    foreach ([route('leave.register'), route('leave.register', ['phase' => 'away']), route('leave.settings'), route('leave.settings', ['type' => $this->annual->id]), route('leave.balances'),
        route('leave.types.index'), route('leave.entitlements.index'), route('leave.holidays.index'), route('staff.index', ['q' => 'Efe']), route('leave.approvals'),
        route('messaging.settings'), route('messaging.log')] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get(route('staff.index', ['q' => 'Efe']))->assertSee('Staff Efe')->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
});

it('lets the leave office change reminders and the approval route, and every person their own text settings', function () {
    $this->actingAs($this->admin)->put(route('leave.settings.update'), ['coverage_percent' => 50, 'remind_before' => 5, 'remind_left' => '5, 2', 'late_grace' => 2, 'weekend' => [5, 6, 7]])->assertSessionHas('success');
    expect(LeaveService::setting('remind_left'))->toBe('5,2')->and(LeaveService::isWorkingDay(Carbon::parse('2027-03-19')))->toBeFalse();

    $sick = LeaveType::where('code', 'sick')->first();
    $this->actingAs($this->admin)->put(route('leave.routes.update'), ['leave_type_id' => $sick->id, 'steps' => [['label' => 'Medical officer', 'approver' => 'role', 'role_name' => 'HR Officer', 'due_days' => 1, 'can_skip_if_missing' => 1]]])->assertSessionHas('success');
    expect($this->leave->routeFor($sick)->pluck('label')->all())->toBe(['Medical officer'])
        ->and($this->leave->routeFor($this->annual)->count())->toBe(3);

    Setting::put('messaging.topic.leave.sms', '1');
    $this->actingAs($this->staff)->put(route('notifications.preferences.save'), ['phone_number' => '08031112222'])->assertSessionHas('success');
    expect($this->staff->fresh()->notify_sms)->toBeFalse();
    applyFor($this);
    expect(OutboundMessage::where('user_id', $this->staff->id)->exists())->toBeFalse();
});

it('stores messaging secrets encrypted and never shows them back', function () {
    $this->actingAs($this->admin)->put(route('messaging.settings.update'), ['sms_provider' => 'termii', 'whatsapp_provider' => 'log', 'country_code' => '234', 'termii_key' => 'TL-secret-123', 'termii_sender' => 'AFUED'])->assertSessionHas('success');
    expect(Setting::get('messaging.termii_key'))->not->toContain('TL-secret')->and(\App\Messaging\Gateway::get('termii_key'))->toBe('TL-secret-123');
    $this->actingAs($this->admin)->get(route('messaging.settings'))->assertOk()->assertDontSee('TL-secret-123')->assertSee('Saved. Leave empty to keep it.');
    $this->actingAs($this->admin)->put(route('messaging.settings.update'), ['sms_provider' => 'twilio', 'whatsapp_provider' => 'log', 'country_code' => '234'])->assertSessionHas('error');
});
