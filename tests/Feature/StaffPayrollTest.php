<?php

use App\Data\Payroll\TaxTable;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OperationBatch;
use App\Models\PayElement;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryScale;
use App\Models\SalaryStructure;
use App\Models\StaffPayItem;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Hr\RetirementRules;
use App\Services\Payroll\PayrollService;
use App\Services\Payroll\PayrollSettings;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2027-03-01 08:00:00');
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $person = fn ($name, $extra = []) => User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@example.com', 'password' => 'secret-pass'] + $extra);
    $this->ada = $person('Ada Lecturer', ['date_of_birth' => '1962-06-15']);
    $this->bayo = $person('Bayo Newstaff');

    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $this->dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $this->conuass = SalaryStructure::where('code', 'CONUASS')->first();
    SalaryScale::create(['salary_structure_id' => $this->conuass->id, 'grade_level' => 4, 'step' => 3, 'annual_basic' => 6000000]);
    SalaryScale::create(['salary_structure_id' => $this->conuass->id, 'grade_level' => 2, 'step' => 1, 'annual_basic' => 3100000]);
    PayElement::create(['code' => 'HOUSING', 'name' => 'Rent allowance', 'kind' => 'earning', 'method' => 'fixed', 'amount' => 100000, 'taxable' => true, 'pensionable' => true]);
    PayElement::create(['code' => 'CONTISS_ONLY', 'name' => 'Shift allowance', 'kind' => 'earning', 'method' => 'fixed', 'amount' => 5000, 'applies_to' => ['structures' => ['CONTISS']]]);

    $base = ['department_id' => $this->dept->id, 'salary_structure_id' => $this->conuass->id, 'category' => 'academic',
        'bank_name' => 'First Bank', 'bank_code' => '011', 'bank_account_number' => '0123456789', 'bank_account_name' => 'ADA LECTURER',
        'pfa_code' => 'PFA1', 'rsa_pin' => 'PEN100000000001', 'tax_state' => 'Ondo'];
    $this->pa = StaffProfile::create($base + ['user_id' => $this->ada->id, 'staff_number' => 'SP/001', 'grade_level' => 4, 'step' => 3, 'first_appointment_date' => '1995-01-01']);
    $this->pb = StaffProfile::create(['user_id' => $this->bayo->id, 'staff_number' => 'SP/002', 'grade_level' => 2, 'step' => 1, 'first_appointment_date' => '2027-03-16'] + $base);
    $this->loan = StaffPayItem::create(['user_id' => $this->ada->id, 'kind' => 'deduction', 'name' => 'Car loan', 'amount' => 50000, 'balance' => 70000, 'start_month' => '2027-01']);
    $this->payroll = app(PayrollService::class);
});

afterEach(fn () => Carbon::setTestNow());

it('works out tax from the bands', function () {
    $t = TaxTable::fromArray(PayrollSettings::DEFAULT_TAX_BANDS);
    expect($t->annualTax(800000))->toBe(0.0)
        ->and($t->annualTax(3000000))->toBe(330000.0)
        ->and($t->annualTax(6474000))->toBe(955320.0)
        ->and($t->annualTax(60000000))->toBe(330000.0 + 1620000 + 2730000 + 5750000 + 2500000.0);
});

it('works out a payslip: allowances, pension and NHF before tax, then tax, then a loan capped at its balance', function () {
    $d = $this->payroll->draftFor($this->pa, \Carbon\CarbonImmutable::parse('2027-03-01'));
    // basic 500,000 + rent 100,000 = 600,000 gross and pensionable; pension 8% = 48,000; NHF 2.5% of basic = 12,500
    // taxable 539,500 a month = 6,474,000 a year -> 955,320 tax a year -> 79,610 a month
    expect($d->basic)->toBe(500000.0)->and($d->gross)->toBe(600000.0)
        ->and($d->pensionEmployee)->toBe(48000.0)->and($d->pensionEmployer)->toBe(60000.0)
        ->and($d->taxable)->toBe(539500.0)->and($d->tax)->toBe(79610.0)
        ->and($d->totalDeductions)->toBe(48000 + 12500 + 79610 + 50000.0)
        ->and($d->net)->toBe(600000 - 190110.0)
        ->and(collect($d->lines)->pluck('code')->filter()->values()->all())->not->toContain('CONTISS_ONLY');
});

it('pays part of the month to someone who joined mid-month or took unpaid leave', function () {
    $d = $this->payroll->draftFor($this->pb, \Carbon\CarbonImmutable::parse('2027-03-01'));
    expect($d->factor)->toBe(round(16 / 31, 4))->and($d->basic)->toBe(round(3100000 / 12 * 16 / 31, 2))
        ->and($d->notes)->toContain('Paid for 51.6% of the month.');

    $unpaid = LeaveType::create(['code' => 'unpaid', 'name' => 'Leave without pay', 'paid' => false, 'days' => 30]);
    LeaveRequest::create(['reference' => 'LV/1', 'user_id' => $this->ada->id, 'leave_type_id' => $unpaid->id, 'year' => 2027, 'start_date' => '2027-03-01', 'end_date' => '2027-03-10',
        'days' => 10, 'expected_resumption' => '2027-03-11', 'reason' => 'Personal', 'status' => 'approved']);
    expect($this->payroll->factor($this->pa->fresh(), \Carbon\CarbonImmutable::parse('2027-03-01')))->toBe(21 / 31);
});

it('prepares, approves and pays a month; approval lowers loans and tells staff; it can be reopened until paid', function () {
    $this->actingAs($this->admin)->post(route('payroll.runs.store'), ['period' => '2027-03'])->assertRedirect();
    $run = PayrollRun::where('period', '2027-03')->firstOrFail();
    expect($run->status)->toBe('draft')->and($run->staff_count)->toBe(2);
    $this->get(route('payroll.runs.show', $run))->assertOk()->assertSee('Ada Lecturer')->assertSee('409,890.00');
    $this->get(route('payroll.payslips.show', Payslip::where('user_id', $this->ada->id)->first()))->assertOk()->assertSee('Car loan')->assertSee('******6789');
    $this->get(route('payroll.runs.index'))->assertOk()->assertSee('March 2027');

    // staff cannot see a draft
    $slip = Payslip::where(['payroll_run_id' => $run->id, 'user_id' => $this->ada->id])->first();
    $this->actingAs($this->ada)->get(route('my.payslips.show', $slip))->assertNotFound();

    Carbon::setTestNow('2027-03-25 09:00:00');
    $this->actingAs($this->admin)->post(route('payroll.runs.approve', $run))->assertSessionHas('success');
    expect($run->fresh()->status)->toBe('approved')->and($this->loan->fresh()->balance)->toBe(20000.0)
        ->and($this->ada->notifications()->where('data', 'like', '%Payslip for March 2027%')->count())->toBe(1);

    $this->actingAs($this->ada)->get(route('my.payslips'))->assertOk()->assertSee('March 2027');
    $this->get(route('my.payslips.show', $slip))->assertOk()->assertSee('409,890.00')->assertSee('0123456789');
    $other = Payslip::where(['payroll_run_id' => $run->id, 'user_id' => $this->bayo->id])->first();
    $this->get(route('my.payslips.show', $other))->assertNotFound();
    $this->get(route('payroll.payslips.show', $slip))->assertForbidden();

    // reopen through the operation history: loan balance goes back
    $this->actingAs($this->admin);
    $op = OperationBatch::where('type', 'payroll_approve')->latest('id')->firstOrFail();
    confirmReversal($this, $op, null, 'Approved before the bursar checked the overtime.')->assertSessionHas('success');
    expect($run->fresh()->status)->toBe('draft')->and($this->loan->fresh()->balance)->toBe(70000.0);

    Carbon::setTestNow('2027-03-26 09:00:00');
    $this->post(route('payroll.runs.store'), ['period' => '2027-03']);
    $this->post(route('payroll.runs.approve', $run))->assertSessionHas('success');
    $this->post(route('payroll.runs.paid', $run))->assertSessionHas('success');
    expect($run->fresh()->status)->toBe('paid');
    $this->post(route('payroll.runs.store'), ['period' => '2027-03'])->assertSessionHas('error');

    // next month the loan takes only what is left
    Carbon::setTestNow('2027-04-20 09:00:00');
    $april = $this->payroll->draftFor($this->pa->fresh(), \Carbon\CarbonImmutable::parse('2027-04-01'));
    expect(collect($april->lines)->firstWhere('staffPayItemId', $this->loan->id)->amount)->toBe(20000.0);
});

it('refuses to approve when something changed after the payroll was prepared', function () {
    $run = $this->payroll->prepare('2027-03', $this->admin);
    Carbon::setTestNow('2027-03-02 08:00:00');
    $this->actingAs($this->admin)->put(route('staff.pay', $this->pa), ['salary_structure_id' => $this->conuass->id, 'grade_level' => 4, 'step' => 3, 'bank_account_number' => '9999999999'])
        ->assertSessionHas('success');
    $this->get(route('payroll.runs.show', $run))->assertSee('changed after this payroll was prepared');
    $this->post(route('payroll.runs.approve', $run))->assertSessionHas('error');
    expect($run->fresh()->status)->toBe('draft');
});

it('lists people left out and flags missing bank or pension details', function () {
    $this->pb->update(['step' => 9, 'bank_account_number' => null]);   // no scale amount for 2/9
    $this->pa->update(['rsa_pin' => null]);
    $run = $this->payroll->prepare('2027-03', $this->admin);
    expect($run->staff_count)->toBe(1)->and($run->problems[0])->toContain('Bayo Newstaff')
        ->and(Payslip::where('user_id', $this->ada->id)->first()->notes)->toContain('Pension is deducted but the PFA or RSA PIN is missing.');
});

it('gives the bank, pension, tax and deduction schedules, on screen and as CSV', function () {
    $run = $this->payroll->prepare('2027-03', $this->admin);
    $this->actingAs($this->admin);
    $this->get(route('payroll.runs.schedule', [$run, 'bank']))->assertOk()->assertSee('0123456789');
    $csv = $this->get(route('payroll.runs.schedule', [$run, 'bank', 'download' => 1]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    expect($csv)->toContain('Account number')->toContain('0123456789')->toContain('Total');
    $this->get(route('payroll.runs.schedule', [$run, 'pension', 'pfa' => 'PFA1']))->assertOk()->assertSee('PEN100000000001');
    $this->get(route('payroll.runs.schedule', [$run, 'tax']))->assertOk()->assertSee('Ondo');
    $this->get(route('payroll.runs.schedule', [$run, 'deduction', 'item' => 'Car loan']))->assertOk()->assertSee('50,000.00');
    $this->get(route('payroll.runs.schedule', [$run, 'nonsense']))->assertNotFound();
});

it('checks pay details and keeps pay pages behind their permissions', function () {
    $this->actingAs($this->admin)->put(route('staff.pay', $this->pa), ['rsa_pin' => 'PEN12', 'bank_account_number' => '123'])->assertSessionHasErrors(['rsa_pin', 'bank_account_number']);
    $this->post(route('staff.items.store', $this->pa), ['kind' => 'deduction', 'name' => 'Staff co-operative', 'amount' => 10000, 'start_month' => '2027-03'])->assertSessionHas('success');
    $this->get(route('staff.show', $this->pa))->assertOk()->assertSee('Staff co-operative')->assertSee('Pay, pension and tax');

    $this->actingAs($this->ada);
    foreach ([route('payroll.runs.index'), route('payroll.settings'), route('payroll.structures.index'), route('payroll.elements.index'), route('staff.index')] as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('saves the tax bands only with exactly one open top band', function () {
    $this->actingAs($this->admin);
    $ok = ['bands' => [['upto' => 1000000, 'rate' => 0], ['upto' => null, 'rate' => 20]], 'paid' => ['active'], 'retirement_age' => 65, 'retirement_age_professorial' => 70, 'max_service_years' => 35];
    $this->put(route('payroll.settings.update'), $ok)->assertSessionHas('success');
    expect(app(PayrollSettings::class)->taxTable()->annualTax(2000000))->toBe(200000.0);
    $this->put(route('payroll.settings.update'), ['bands' => [['upto' => 1000000, 'rate' => 0]]] + $ok)->assertSessionHas('error');
    $this->get(route('payroll.settings'))->assertOk();
});

it('works out retirement from age or years of service, whichever is first', function () {
    $r = app(RetirementRules::class)->for($this->pa);   // born 1962-06-15: 65 on 2027-06-15; 35 years of service on 2030-01-01
    expect($r->date->toDateString())->toBe('2027-06-15');
    $this->pa->update(['professorial' => true]);   // 70 in 2032, so service (2030) comes first
    expect(app(RetirementRules::class)->for($this->pa->fresh())->date->toDateString())->toBe('2030-01-01');
    $this->actingAs($this->admin)->get(route('staff.retirements', ['months' => 60]))->assertOk()->assertSee('Ada Lecturer');
});

it('shows the setup pages', function () {
    $this->actingAs($this->admin);
    foreach ([route('staff.index'), route('units.index'), route('payroll.structures.index'), route('payroll.elements.index'), route('payroll.scales.show', $this->conuass), route('payroll.runs.index'), route('scoped-roles.index')] as $url) {
        $this->get($url)->assertOk();
    }
    $this->put(route('payroll.scales.update', $this->conuass), ['amount' => [4 => [4 => 6200000]]])->assertSessionHas('success');
    expect((float) SalaryScale::where(['salary_structure_id' => $this->conuass->id, 'grade_level' => 4, 'step' => 4])->value('annual_basic'))->toBe(6200000.0);
});
