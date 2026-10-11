<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\BillMatcher;
use App\Services\InvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();

    $this->faculty = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $this->other   = Faculty::create(['name' => 'Faculty of Arts', 'code' => 'ART']);
    $dept = Department::create(['faculty_id' => $this->faculty->id, 'name' => 'Computer Science', 'code' => 'CSC']);
    $this->programme = Programme::create(['department_id' => $dept->id, 'name' => 'B.Sc. Computer Science', 'code' => 'BSC-CSC', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2025/2026', 'is_current' => true]);
    $this->tuition = BillItem::create(['name' => 'Tuition', 'code' => 'TUI']);
    $this->lab     = BillItem::create(['name' => 'Lab fee', 'code' => 'LAB']);
});

function enrol(string $email = 'a@example.com', string $nationality = 'Nigerian'): Student
{
    $a = Applicant::create([
        'first_name' => 'Ada', 'last_name' => 'Okafor', 'email' => $email, 'programme_id' => test()->programme->id,
        'academic_session_id' => test()->session->id, 'entry_mode' => 'utme', 'entry_level' => 100, 'nationality' => $nationality,
    ]);

    return app(AdmissionService::class)->admit($a)->student;
}

function bill(array $over): Bill
{
    return Bill::create($over + ['academic_session_id' => test()->session->id, 'bill_item_id' => test()->tuition->id, 'student_type' => 'all', 'amount' => 1000]);
}

it('applies a faculty-wide bill to every programme in it but not to other faculties', function () {
    bill(['faculty_id' => $this->faculty->id, 'amount' => 5000]);
    bill(['faculty_id' => $this->other->id, 'bill_item_id' => $this->lab->id, 'amount' => 9999]);

    $invoice = app(InvoiceService::class)->generateForStudent(enrol(), $this->session);

    expect((float) $invoice->total)->toBe(5000.0)->and($invoice->lines)->toHaveCount(1);
});

it('charges foreign students the foreign bill instead of the general one for the same item', function () {
    bill(['amount' => 100000]);
    bill(['nationality' => 'foreign', 'amount' => 300000]);

    $home = app(InvoiceService::class)->generateForStudent(enrol('home@example.com'), $this->session);
    $foreign = app(InvoiceService::class)->generateForStudent(enrol('abroad@example.com', 'Ghanaian'), $this->session);

    expect((float) $home->total)->toBe(100000.0)->and((float) $foreign->total)->toBe(300000.0);
});

it('lets the university choose its own home nationality', function () {
    \App\Models\Setting::put('institution.home_nationality', 'Ghanaian');
    bill(['amount' => 100]);
    bill(['nationality' => 'foreign', 'amount' => 500]);

    expect((float) app(InvoiceService::class)->generateForStudent(enrol('g@example.com', 'Ghanaian'), $this->session)->total)->toBe(100.0);
});

it('keeps optional bill items off automatic invoices', function () {
    $this->lab->update(['is_compulsory' => false]);
    bill(['amount' => 100]);
    bill(['bill_item_id' => $this->lab->id, 'amount' => 50]);

    expect((float) app(InvoiceService::class)->generateForStudent(enrol(), $this->session)->total)->toBe(100.0);
});

it('bills semester fees on their own invoice', function () {
    $second = SemesterType::where('sort_order', 2)->first();
    bill(['amount' => 100]);
    bill(['bill_item_id' => $this->lab->id, 'semester_type_id' => $second->id, 'amount' => 40]);
    $student = enrol();
    $svc = app(InvoiceService::class);

    $sessionInvoice = $svc->generateForStudent($student, $this->session);
    $semInvoice = $svc->generateForStudent($student, $this->session, null, $second->id);

    expect((float) $sessionInvoice->total)->toBe(100.0)
        ->and((float) $semInvoice->total)->toBe(40.0)
        ->and($semInvoice->id)->not->toBe($sessionInvoice->id)
        ->and($svc->generateForStudent($student, $this->session, null, $second->id)->id)->toBe($semInvoice->id);
});

it('splits a bill into instalments and asks for the first one now', function () {
    bill(['amount' => 100000, 'installments' => 2, 'installment_percents' => [60, 40]]);
    $invoice = app(InvoiceService::class)->generateForStudent(enrol(), $this->session);

    expect($invoice->installment_plan)->toEqual([60000, 40000])
        ->and($invoice->minimumPayment())->toBe(60000.0);

    app(InvoiceService::class)->recordOfflinePayment($invoice, 70000, 'cash', null, null, null, $this->admin->id);
    expect($invoice->fresh()->minimumPayment())->toBe(30000.0);
});

it('adds instalments of several bills together', function () {
    $m = new BillMatcher();
    $a = new Bill(['amount' => 100, 'installments' => 2, 'installment_percents' => [50, 50]]);
    $b = new Bill(['amount' => 30, 'installments' => 1]);

    expect($m->plan(collect([$a, $b])))->toBe([80.0, 50.0]);
});

it('records the payment window on the invoice', function () {
    bill(['amount' => 100, 'opens_at' => now()->addDay(), 'closes_at' => now()->addDays(5)]);
    $invoice = app(InvoiceService::class)->generateForStudent(enrol(), $this->session);
    expect($invoice->windowState())->toBe('not_yet');

    $this->travel(6)->days();
    expect($invoice->fresh()->windowState())->toBe('closed');
});

it('creates targeted bills from the form with blank targets meaning everyone', function () {
    $this->actingAs($this->admin)->post(route('bills.structure.store'), [
        'academic_session_id' => $this->session->id, 'bill_item_id' => $this->tuition->id, 'amount' => 90000,
        'student_type' => 'all', 'nationality' => 'any', 'installments' => 2, 'pct_1' => 50, 'pct_2' => 50,
        'faculty_id' => $this->faculty->id, 'programme_id' => '', 'level' => '',
    ])->assertSessionHasNoErrors();

    $bill = Bill::first();
    expect($bill->programme_id)->toBeNull()->and($bill->faculty_id)->toBe($this->faculty->id)->and($bill->installment_percents)->toEqual([50, 50]);

    $this->post(route('bills.structure.store'), [
        'academic_session_id' => $this->session->id, 'bill_item_id' => $this->tuition->id, 'amount' => 1,
        'student_type' => 'all', 'nationality' => 'any', 'installments' => 1, 'faculty_id' => $this->faculty->id,
    ])->assertSessionHasErrors('bill_item_id');

    $this->post(route('bills.structure.store'), [
        'academic_session_id' => $this->session->id, 'bill_item_id' => $this->lab->id, 'amount' => 1,
        'student_type' => 'all', 'installments' => 2, 'pct_1' => 70, 'pct_2' => 20,
    ])->assertSessionHasErrors('pct_1');
});
