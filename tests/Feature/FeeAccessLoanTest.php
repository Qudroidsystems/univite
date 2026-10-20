<?php

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\Semester;
use App\Models\StudentLoan;
use App\Models\User;
use App\Services\Fees\FeeAccess;
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
    $c = Course::create(['department_id' => $this->dept->id, 'code' => 'PHY 101', 'title' => 'Mechanics', 'credit_units' => 15, 'level' => 100]);
    ProgrammeCourse::create(['programme_id' => $this->prog->id, 'course_id' => $c->id, 'level' => 100, 'semester_order' => 1, 'status' => 'compulsory']);
    $this->course = $c;
    $this->student = makeStudent($this, 'Bola');
    $this->invoice = Invoice::create(['reference' => 'INV-2026-000001', 'kind' => 'fees', 'payable_type' => \App\Models\Student::class, 'payable_id' => $this->student->id,
        'academic_session_id' => $this->session->id, 'title' => '2026/2027 school fees', 'total' => 200000, 'amount_paid' => 0, 'status' => 'unpaid']);
});

function rules(array $over = []): void
{
    test()->actingAs(test()->admin)->put(route('fee-access.update'), $over + [
        'registration_on' => 1, 'registration_percent' => 50, 'exams_on' => 1, 'exams_percent' => 100, 'results_on' => 1, 'results_percent' => 100,
        'portal_on' => 0, 'portal_percent' => 25, 'count_loans' => 1, 'loan_overdue_blocks' => 1,
    ])->assertSessionHas('success');
}

function pay(float $amount): void
{
    app(InvoiceService::class)->recordOfflinePayment(test()->invoice->fresh(), $amount, 'bank_deposit', 'T' . uniqid(), null, null, test()->admin->id);
}

it('locks registration, examinations and results until the share of fees set is paid', function () {
    rules();
    $s = $this->actingAs($this->student->user->fresh());
    $s->get(route('my.registration'))->assertSee('50% is needed to register courses');
    $s->get(route('my.results'))->assertOk()->assertSee('Your results are held');
    $s->get(route('my.exams.slip'))->assertOk()->assertSee('You cannot print your examination slip yet');

    pay(100000);
    $s->get(route('my.registration'))->assertDontSee('is needed to register courses');
    $s->post(route('my.registration.save'), ['courses' => [$this->course->id], 'submit' => 1])->assertSessionHas('success');
    $s->get(route('my.results'))->assertSee('Your results are held');

    pay(100000);
    $s->get(route('my.results'))->assertDontSee('Your results are held');

    // the staff list shows who is locked
    $this->actingAs($this->admin)->get(route('fee-access.index'))->assertOk()->assertSee('Student Bola');
    $this->get(route('fee-access.excel'))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $this->get(route('fee-access.print'))->assertOk()->assertSee('Fee eligibility list');
});

it('locks the whole portal and app but keeps fees and loans open, and lets staff allow a student', function () {
    rules(['portal_on' => 1, 'portal_percent' => 25]);
    $user = $this->student->user->fresh();
    $this->actingAs($user)->get(route('my.timetable'))->assertRedirect(route('my.access-locked'));
    $this->get(route('my.access-locked'))->assertOk()->assertSee('Your portal is locked until you pay');
    $this->get(route('my.fees'))->assertOk();

    $token = $user->createToken('phone')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/results')->assertStatus(403)->assertJsonPath('code', 'fees_access');
    $this->withToken($token)->getJson('/api/v1/fees')->assertOk()->assertJsonPath('meta.standing.access.portal.allowed', false);

    $this->actingAs($this->admin)->post(route('fee-access.override', $this->student->id), ['area' => 'portal', 'session_id' => $this->session->id, 'decision' => 'allow', 'reason' => ''])
        ->assertSessionHas('error');
    $this->post(route('fee-access.override', $this->student->id), ['area' => 'portal', 'session_id' => $this->session->id, 'decision' => 'allow', 'reason' => 'Scholarship letter seen'])
        ->assertSessionHas('success');
    $this->actingAs($user)->get(route('my.timetable'))->assertOk();

    // barring by hand works even when fees are paid
    pay(200000);
    $this->actingAs($this->admin)->post(route('fee-access.override', $this->student->id), ['area' => 'exams', 'session_id' => $this->session->id, 'decision' => 'bar', 'reason' => 'Exam malpractice panel'])->assertSessionHas('success');
    $this->actingAs($user)->get(route('my.exams.slip'))->assertSee('Exam malpractice panel');
});

it('runs a school loan from application to repayment, counting it as fees paid', function () {
    rules();
    $this->actingAs($this->admin)->put(route('loans.settings'), ['enabled' => 1, 'require_guarantor' => 1, 'max_percent' => 50, 'interest_percent' => 10,
        'max_instalments' => 4, 'interval_days' => 30, 'first_due_days' => 30, 'terms' => 'Repay on time.'])->assertSessionHas('success');

    $s = $this->actingAs($this->student->user->fresh());
    $s->get(route('my.loans'))->assertOk()->assertSee('100,000.00');
    $s->post(route('my.loans.apply'), ['amount' => 150000, 'guarantor_name' => 'Mr Ade', 'guarantor_phone' => '0803', 'accept' => 1])->assertSessionHas('error', fn ($m) => str_contains($m, 'up to 100,000.00'));
    $s->post(route('my.loans.apply'), ['amount' => 100000, 'accept' => 1])->assertSessionHas('error', fn ($m) => str_contains($m, 'guarantor'));
    $s->post(route('my.loans.apply'), ['amount' => 100000, 'guarantor_name' => 'Mr Ade', 'guarantor_phone' => '0803', 'accept' => 1])->assertSessionHas('success');
    $loan = StudentLoan::firstOrFail();
    expect($loan->status)->toBe('pending')->and($loan->reference)->toStartWith('LN-2026-');

    $this->actingAs($this->admin)->get(route('loans.index'))->assertOk()->assertSee($loan->reference);
    $this->post(route('loans.approve', $loan->id), ['amount' => 100000, 'instalments' => 2, 'first_due' => today()->addDays(10)->toDateString()])->assertSessionHas('success');
    $loan->refresh();
    expect((float) $loan->amount_due)->toBe(110000.0)->and($loan->instalments)->toHaveCount(2)->and($loan->instalments[0]['amount'])->toEqual(55000);

    $this->post(route('loans.disburse', $loan->id))->assertSessionHas('success');
    expect((float) $this->invoice->fresh()->amount_paid)->toBe(100000.0)->and($loan->fresh()->status)->toBe('disbursed');
    // 50% paid through the loan: registration opens
    expect(app(FeeAccess::class)->blocker($this->student->fresh(), 'registration'))->toBeNull();

    // an overdue instalment locks again
    $this->travel(15)->days();
    expect(StudentLoan::first()->overdue())->toBe(55000.0);
    expect(app(FeeAccess::class)->blocker($this->student->fresh(), 'registration'))->toContain('loan repayment of 55,000.00 is overdue');

    $this->post(route('loans.repay', $loan->id), ['amount' => 55000, 'channel' => 'bank_transfer'])->assertSessionHas('success');
    expect(app(FeeAccess::class)->blocker($this->student->fresh(), 'registration'))->toBeNull();
    $this->post(route('loans.repay', $loan->id), ['amount' => 60000, 'channel' => 'cash'])->assertSessionHas('error');
    $this->post(route('loans.repay', $loan->id), ['amount' => 55000, 'channel' => 'cash'])->assertSessionHas('success');
    expect($loan->fresh()->status)->toBe('settled');

    $this->app['auth']->forgetGuards();
    $this->withToken($this->student->user->createToken('p')->plainTextToken)->getJson('/api/v1/loans')->assertOk()->assertJsonPath('data.0.status', 'settled');
});

it('does not count loans as paid when the school says so, and never holds back a student with nothing billed', function () {
    rules(['count_loans' => 0]);
    $this->invoice->update(['status' => 'cancelled']);
    expect(app(FeeAccess::class)->blocker($this->student->fresh(), 'results'))->toBeNull();
});
