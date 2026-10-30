<?php

use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Course;
use App\Models\Department;
use App\Models\DepartmentFeeRule;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $f = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $this->dept = Department::create(['faculty_id' => $f->id, 'name' => 'Physics', 'code' => 'PHY']);
    $this->prog = Programme::create(['department_id' => $this->dept->id, 'name' => 'B.Sc. Physics', 'code' => 'BSC-PHY', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $this->course = Course::create(['department_id' => $this->dept->id, 'code' => 'PHY 101', 'title' => 'Mechanics', 'credit_units' => 15, 'level' => 100]);
    ProgrammeCourse::create(['programme_id' => $this->prog->id, 'course_id' => $this->course->id, 'level' => 100, 'semester_order' => 1, 'status' => 'compulsory']);
    $this->student = makeStudent($this, 'Bola');

    $this->tuition = BillItem::create(['name' => 'Tuition', 'code' => 'TUI']);
    $this->hostel = BillItem::create(['name' => 'Hostel', 'code' => 'HOS']);
    foreach ([[$this->tuition, 150000], [$this->hostel, 60000]] as [$item, $amount]) {
        Bill::create(['academic_session_id' => $this->session->id, 'bill_item_id' => $item->id, 'student_type' => 'all', 'amount' => $amount]);
    }
});

it('puts a fee paid separately on its own invoice and keeps the rest together', function () {
    $this->actingAs($this->admin)->put(route('bills.items.update', $this->hostel->id), [
        'name' => 'Hostel', 'code' => 'HOS', 'payment_mode' => 'separate', 'max_payments' => 1, 'is_compulsory' => 1, 'is_active' => 1,
    ])->assertSessionHasNoErrors();

    $main = app(InvoiceService::class)->generateForStudent($this->student, $this->session);
    expect((float) $main->total)->toBe(150000.0)->and($main->bill_item_id)->toBeNull();

    $own = Invoice::where('bill_item_id', $this->hostel->id)->sole();
    expect((float) $own->total)->toBe(60000.0)->and($own->max_payments)->toBe(1)->and($own->minimumPayment())->toBe(60000.0)
        ->and($own->title)->toContain('Hostel');

    // running it again creates nothing new
    app(InvoiceService::class)->generateForStudent($this->student, $this->session);
    expect(Invoice::where('payable_id', $this->student->id)->count())->toBe(2);

    // the school default can make every fee separate
    $this->put(route('payments.settings.update'), ['default_payment_mode' => 'separate', 'default_max_payments' => 0, 'abandon_hours' => 72, 'remita_mode' => 'demo'])->assertSessionHasNoErrors();
    expect($this->tuition->fresh()->paysSeparately())->toBeTrue();
});

it('lets a fee be paid in up to a set number of payments, the last clearing the balance', function () {
    $this->tuition->update(['max_payments' => 3]);
    $this->hostel->update(['max_payments' => 3]);
    $invoice = app(InvoiceService::class)->generateForStudent($this->student, $this->session);
    expect($invoice->max_payments)->toBe(3)->and($invoice->paymentsLeft())->toBe(3)->and($invoice->minimumPayment())->toBe(70000.0);

    $svc = app(InvoiceService::class);
    $svc->recordOfflinePayment($invoice->fresh(), 100000, 'bank_deposit', 'T1', null, null, $this->admin->id);
    $svc->recordOfflinePayment($invoice->fresh(), 10000, 'bank_deposit', 'T2', null, null, $this->admin->id);
    $invoice->refresh();
    expect($invoice->paymentsLeft())->toBe(1)->and($invoice->minimumPayment())->toBe(100000.0);

    $this->actingAs($this->student->user->fresh())->get(route('my.fees.show', $invoice))->assertOk()->assertSee('paid in full in your next payment');
});

it('lets a department register before paying and shows what is owed', function () {
    $this->actingAs($this->admin)->put(route('fee-access.update'), [
        'registration_on' => 1, 'registration_percent' => 100, 'exams_on' => 0, 'exams_percent' => 100, 'results_on' => 0, 'results_percent' => 100,
        'portal_on' => 0, 'portal_percent' => 25,
    ])->assertSessionHas('success');
    app(InvoiceService::class)->generateForStudent($this->student, $this->session);

    $s = $this->actingAs($this->student->user->fresh());
    $s->get(route('my.registration'))->assertSee('100% is needed to register courses')->assertDontSee('You owe');

    $this->actingAs($this->admin)->post(route('fees.department-rules.store'), [
        'department_id' => $this->dept->id, 'register_without_paying' => 1, 'show_owing' => 1, 'note' => 'Pay before the examinations.', 'is_active' => 1,
    ])->assertSessionHasNoErrors();
    expect(DepartmentFeeRule::count())->toBe(1);
    $this->get(route('fees.department-rules.index'))->assertOk()->assertSee('Physics');

    $s = $this->actingAs($this->student->user->fresh());
    $s->get(route('my.registration'))->assertDontSee('is needed to register courses')->assertSee('You owe ₦210,000.00')->assertSee('Pay before the examinations.');
    $s->post(route('my.registration.save'), ['courses' => [$this->course->id], 'submit' => 1])->assertSessionHas('success');
    $s->get(route('my.results'))->assertOk()->assertSee('You owe ₦210,000.00');
    $s->get(route('dashboard'))->assertOk()->assertSee('You owe ₦210,000.00');

    $token = $this->student->user->fresh()->createToken('app')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/results')->assertOk()->assertJsonPath('meta.owing.amount', 210000);
    $this->withToken($token)->getJson('/api/v1/courses')->assertOk()->assertJsonPath('data.owing.note', 'Pay before the examinations.');
});
