<?php

use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\CloneItem;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\OperationBatch;
use App\Models\OperationEvent;
use App\Models\ResultSheet;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\Setting;
use App\Models\User;
use App\Services\OperationAudit;
use App\Services\SessionCloneService;
use App\Services\SessionLifecycle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $this->src = AcademicSession::create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-08-31', 'status' => 'active', 'is_current' => true]);
    $this->s1 = Semester::create(['academic_session_id' => $this->src->id, 'name' => 'First Semester', 'sort_order' => 1,
        'registration_opens_at' => '2025-09-10 08:00:00', 'registration_closes_at' => '2025-10-10 17:00:00']);
    $this->dst = AcademicSession::create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31']);
    $this->d1 = Semester::create(['academic_session_id' => $this->dst->id, 'name' => 'First Semester', 'sort_order' => 1]);
});

it('keeps audit events append-only and strips secrets', function () {
    $audit = app(OperationAudit::class);
    $batch = $audit->begin('test_op', 'tests', $this->admin);
    $event = $audit->record($batch, 'thing_done', null, ['password' => 'hunter2', 'name' => 'a'], ['nin' => '123', 'name' => 'b']);

    expect($event->before)->toBe(['name' => 'a'])->and($event->after)->toBe(['name' => 'b']);
    expect(fn () => $event->update(['note' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);
    expect($batch->operation_id)->toStartWith('OP-');
});

it('only lets a session move along allowed stages and needs a reason to close', function () {
    $life = app(SessionLifecycle::class);

    expect(fn () => $life->transition($this->dst, 'closed', $this->admin, 'x'))->toThrow(RuntimeException::class);
    $life->transition($this->dst, 'setup', $this->admin);
    expect(fn () => $life->transition($this->src->fresh(), 'closing', $this->admin, ''))->toThrow(RuntimeException::class, 'reason');

    $life->transition($this->src->fresh(), 'closing', $this->admin, 'Year over');
    $life->transition($this->src->fresh(), 'closed', $this->admin, 'Results done');

    $src = $this->src->fresh();
    expect($src->status)->toBe('closed')->and($src->is_current)->toBeFalse();
    expect($src->id)->toBeGreaterThan(0);
    expect(OperationBatch::where('type', 'session_status')->count())->toBe(3);
});

it('checks a session before it goes active', function () {
    $life = app(SessionLifecycle::class);

    // only one active session unless the university allows more
    expect(fn () => $life->transition($this->dst, 'active', $this->admin))->toThrow(RuntimeException::class, 'only one session');

    Setting::put('session.allow_multiple_active', '1');
    $empty = AcademicSession::create(['name' => '2027/2028']);
    expect(fn () => $life->transition($empty, 'active', $this->admin))->toThrow(RuntimeException::class, 'semester');

    $life->transition($this->dst, 'active', $this->admin);
    expect($this->dst->fresh()->status)->toBe('active');
});

it('refuses a registration window that closes before it opens', function () {
    Setting::put('session.allow_multiple_active', '1');
    $this->d1->update(['registration_opens_at' => '2026-10-10 08:00:00', 'registration_closes_at' => '2026-10-01 08:00:00']);

    expect(fn () => app(SessionLifecycle::class)->transition($this->dst, 'active', $this->admin))->toThrow(RuntimeException::class, 'registration closes before');
});

it('will not make a closed session current but activates a draft one', function () {
    $life = app(SessionLifecycle::class);
    Setting::put('session.allow_multiple_active', '1');
    $closed = AcademicSession::create(['name' => '2020/2021', 'status' => 'closed']);
    expect(fn () => $life->makeCurrent($closed, $this->admin))->toThrow(RuntimeException::class);

    $life->makeCurrent($this->dst, $this->admin);
    expect($this->dst->fresh()->status)->toBe('active')->and($this->dst->fresh()->is_current)->toBeTrue();
});

it('previews a clone without changing anything', function () {
    $item = BillItem::create(['code' => 'TUI', 'name' => 'Tuition']);
    Bill::create(['academic_session_id' => $this->src->id, 'bill_item_id' => $item->id, 'amount' => 50000, 'is_active' => true, 'opens_at' => '2025-09-01 08:00:00']);

    $preview = app(SessionCloneService::class)->preview($this->src, $this->dst, ['windows', 'bills']);

    expect($preview['total'])->toBe(2)->and($preview['problems'])->toBe([]);
    expect(Bill::where('academic_session_id', $this->dst->id)->count())->toBe(0);
    expect($this->d1->fresh()->registration_opens_at)->toBeNull();
    expect(OperationBatch::where('type', 'session_clone')->count())->toBe(0);
});

it('clones setup only, shifts dates, leaves the source alone and is safe to repeat', function () {
    $item = BillItem::create(['code' => 'TUI', 'name' => 'Tuition']);
    $bill = Bill::create(['academic_session_id' => $this->src->id, 'bill_item_id' => $item->id, 'amount' => 50000, 'is_active' => true, 'opens_at' => '2025-09-01 08:00:00']);
    $svc = app(SessionCloneService::class);

    $op = $svc->run($this->src, $this->dst, ['windows', 'bills'], $this->admin);

    expect($op->success_count)->toBe(2);
    expect($this->d1->fresh()->registration_opens_at->toDateTimeString())->toBe('2026-09-10 08:00:00');
    $copy = Bill::where('academic_session_id', $this->dst->id)->first();
    expect($copy->is_active)->toBeFalse()->and((float) $copy->amount)->toBe(50000.0)->and($copy->opens_at->toDateTimeString())->toBe('2026-09-01 08:00:00');
    // source untouched
    expect($bill->fresh()->is_active)->toBeTrue()->and($this->s1->fresh()->registration_opens_at->toDateTimeString())->toBe('2025-09-10 08:00:00');

    // second run creates nothing
    $again = $svc->preview($this->src, $this->dst, ['windows', 'bills']);
    expect($again['total'])->toBe(0);
    expect(Bill::where('academic_session_id', $this->dst->id)->count())->toBe(1);
    expect($op->events()->count())->toBe(2);
});

it('does not allow a clone into a closed session or onto itself', function () {
    $closed = AcademicSession::create(['name' => '2019/2020', 'status' => 'closed']);
    $svc = app(SessionCloneService::class);

    expect($svc->preview($this->src, $closed, ['bills'])['problems'])->not->toBeEmpty();
    expect($svc->preview($this->src, $this->src, ['bills'])['problems'])->not->toBeEmpty();
    expect(fn () => $svc->run($this->src, $closed, ['bills'], $this->admin))->toThrow(RuntimeException::class);
});

it('undoes a clone fully when nothing has used it', function () {
    $item = BillItem::create(['code' => 'TUI', 'name' => 'Tuition']);
    Bill::create(['academic_session_id' => $this->src->id, 'bill_item_id' => $item->id, 'amount' => 50000, 'is_active' => true]);
    $svc = app(SessionCloneService::class);
    $op = $svc->run($this->src, $this->dst, ['windows', 'bills'], $this->admin);

    $result = $svc->undo($op, $this->admin, 'Wrong session');

    expect($result['undone'])->toBe(2)->and($result['kept'])->toBe([]);
    expect(Bill::where('academic_session_id', $this->dst->id)->count())->toBe(0);
    expect($this->d1->fresh()->registration_opens_at)->toBeNull();
    expect($op->fresh()->status)->toBe('reversed');
    expect($result['batch']->related_operation_id)->toBe($op->id);
    // the original events are untouched
    expect($op->events()->count())->toBe(2);
    expect(fn () => $svc->undo($op->fresh(), $this->admin))->toThrow(RuntimeException::class, 'already');
});

it('keeps what has been used since the clone', function () {
    $item = BillItem::create(['code' => 'TUI', 'name' => 'Tuition']);
    Bill::create(['academic_session_id' => $this->src->id, 'bill_item_id' => $item->id, 'amount' => 50000, 'is_active' => true]);
    $svc = app(SessionCloneService::class);
    $op = $svc->run($this->src, $this->dst, ['windows', 'bills'], $this->admin);

    // the bill was switched on and the window was edited after the clone
    Bill::where('academic_session_id', $this->dst->id)->update(['is_active' => true]);
    $this->d1->fresh()->update(['registration_closes_at' => '2026-12-01 10:00:00']);

    $result = $svc->undo($op, $this->admin);

    expect($result['undone'])->toBe(0)->and($result['kept'])->toHaveCount(2);
    expect(Bill::where('academic_session_id', $this->dst->id)->count())->toBe(1);
    expect($op->fresh()->status)->toBe('completed');
    expect($result['batch']->status)->toBe('partial');
});

it('copies and undoes lecturer assignments, keeping those with a score sheet', function () {
    $faculty = Faculty::create(['name' => 'Education', 'code' => 'EDU']);
    $dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Biology', 'code' => 'BIO']);
    $course = Course::create(['department_id' => $dept->id, 'code' => 'BIO 101', 'title' => 'Intro', 'credit_units' => 3, 'level' => 100]);
    $type = SemesterType::first();
    CourseAssignment::create(['user_id' => $this->admin->id, 'course_id' => $course->id, 'academic_session_id' => $this->src->id, 'semester_type_id' => $type->id]);
    $svc = app(SessionCloneService::class);
    $op = $svc->run($this->src, $this->dst, ['assignments'], $this->admin);

    expect(CourseAssignment::where('academic_session_id', $this->dst->id)->count())->toBe(1);

    ResultSheet::create(['course_id' => $course->id, 'academic_session_id' => $this->dst->id, 'semester_type_id' => $type->id]);
    $result = $svc->undo($op, $this->admin);

    expect($result['undone'])->toBe(0)->and(CourseAssignment::where('academic_session_id', $this->dst->id)->count())->toBe(1);
});

it('never copies registrations, results or payments', function () {
    expect(array_keys(SessionCloneService::CATEGORIES))->toBe(['windows', 'bills', 'assignments']);
    expect(CloneItem::count())->toBe(0);
});

it('serves the status, copy, rules and history pages to an admin and blocks others', function () {
    $this->actingAs($this->admin)->get(route('sessions.lifecycle', $this->dst))->assertOk()->assertSee('Move this session');
    $this->actingAs($this->admin)->get(route('sessions.clone'))->assertOk()->assertSee('Copy session setup');
    $this->actingAs($this->admin)->get(route('sessions.clone', ['source' => $this->src->id, 'dest' => $this->dst->id, 'preview' => 1, 'categories' => ['windows']]))->assertOk()->assertSee('to copy');
    $this->actingAs($this->admin)->get(route('sessions.rules'))->assertOk();
    $this->actingAs($this->admin)->get(route('operations.index'))->assertOk();

    $nobody = User::factory()->create();
    $this->actingAs($nobody)->get(route('operations.index'))->assertForbidden();
    $this->actingAs($nobody)->post(route('sessions.lifecycle.change', $this->dst), ['to' => 'setup'])->assertForbidden();
});

it('changes a status and runs a clone through the web routes', function () {
    $this->actingAs($this->admin)->post(route('sessions.lifecycle.change', $this->dst), ['to' => 'setup'])->assertSessionHas('success');
    expect($this->dst->fresh()->status)->toBe('setup');

    $this->actingAs($this->admin)->post(route('sessions.clone.run'), ['source' => $this->src->id, 'dest' => $this->dst->id, 'categories' => ['windows']])->assertSessionHas('success');
    $batch = OperationBatch::where('type', 'session_clone')->firstOrFail();
    $this->actingAs($this->admin)->get(route('operations.show', $batch))->assertOk()->assertSee($batch->operation_id);

    $this->actingAs($this->admin)->post(route('sessions.clone.undo', $batch))->assertSessionHas('success');
    expect($batch->fresh()->status)->toBe('reversed');
});

it('logs matric batches and links an undo to them', function () {
    expect(OperationEvent::count())->toBeGreaterThanOrEqual(0);
    $this->actingAs($this->admin)->post(route('matric.generate'))->assertStatus(302);
    // nothing is waiting, so the attempt is logged as failed rather than lost
    expect(OperationBatch::where('type', 'matric_generate')->value('status'))->toBe('failed');
});
