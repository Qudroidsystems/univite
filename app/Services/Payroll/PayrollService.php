<?php

namespace App\Services\Payroll;

use App\Data\Payroll\PayslipDraft;
use App\Models\LeaveRequest;
use App\Models\PayElement;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryScale;
use App\Models\StaffPayItem;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\DocumentMailer;
use App\Services\Messenger;
use App\Services\OperationAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A month's payroll: prepared as a draft (prepared again as often as needed while it is checked), approved (payslips
 * are frozen, loan balances go down, staff are told) and then marked paid once the bank has paid. An approval can be
 * reversed from the operation history until the payroll is marked paid.
 */
class PayrollService
{
    public function __construct(
        private PayCalculator $calculator,
        private PayrollSettings $settings,
        private OperationAudit $audit,
        private Messenger $messenger,
        private DocumentMailer $mailer,
    ) {
    }

    public static function validPeriod(string $period): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period);
    }

    /** Staff paid in a month: a salary structure, grade and step, a paid employment status, and in service that month. */
    public function staffFor(CarbonImmutable $start): Collection
    {
        $end = $start->endOfMonth();

        return StaffProfile::with('user', 'department', 'unit', 'salaryStructure')
            ->whereNotNull('salary_structure_id')->whereNotNull('grade_level')
            ->whereIn('employment_status', $this->settings->paidStatuses())
            ->where(fn ($q) => $q->whereNull('first_appointment_date')->orWhereDate('first_appointment_date', '<=', $end))
            ->where(fn ($q) => $q->whereNull('exit_date')->orWhereDate('exit_date', '>=', $start))
            ->whereHas('user', fn ($u) => $u->where('is_disabled', false))
            ->get()->sortBy(fn ($p) => [$p->workplace() ?? 'zz', $p->user->name])->values();
    }

    /** Share of the month a person is paid for: days in service, less days of approved unpaid leave. */
    public function factor(StaffProfile $p, CarbonImmutable $start): float
    {
        $end = $start->endOfMonth()->startOfDay();
        $days = $start->daysInMonth;
        $from = $p->first_appointment_date && $p->first_appointment_date->gt($start) ? CarbonImmutable::parse($p->first_appointment_date) : $start;
        $to = $p->exit_date && $p->exit_date->lt($end) ? CarbonImmutable::parse($p->exit_date) : $end;
        if ($to->lt($from)) {
            return 0.0;
        }
        $paid = $from->diffInDays($to) + 1;

        $unpaid = LeaveRequest::with('type')->where('user_id', $p->user_id)->where('status', 'approved')
            ->whereHas('type', fn ($t) => $t->where('paid', false))
            ->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get()
            ->sum(function ($r) use ($from, $to) {
                $a = CarbonImmutable::parse($r->start_date)->max($from);
                $b = CarbonImmutable::parse($r->end_date)->min($to);

                return $b->lt($a) ? 0 : $a->diffInDays($b) + 1;
            });

        return max(0.0, min(1.0, ($paid - $unpaid) / $days));
    }

    /** Work out one person's payslip for a month without saving anything. */
    public function draftFor(StaffProfile $p, CarbonImmutable $start, ?Collection $elements = null): ?PayslipDraft
    {
        $annual = SalaryScale::where(['salary_structure_id' => $p->salary_structure_id, 'grade_level' => $p->grade_level, 'step' => $p->step ?? 1])->value('annual_basic');
        if ($annual === null) {
            return null;
        }
        $period = $start->format('Y-m');
        $items = StaffPayItem::where('user_id', $p->user_id)->get()->filter(fn (StaffPayItem $i) => $i->runsIn($period))->values();

        return $this->calculator->calculate($p, (float) $annual, $elements ?? $this->elements(), $items, $this->settings->taxTable(), $this->factor($p, $start));
    }

    public function elements(): Collection
    {
        return PayElement::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Prepare (or prepare again) the draft payroll for a month. */
    public function prepare(string $period, User $by): PayrollRun
    {
        if (! self::validPeriod($period)) {
            throw new RuntimeException('Choose a month.');
        }
        $run = PayrollRun::firstOrNew(['period' => $period]);
        if ($run->exists && ! $run->isDraft()) {
            throw new RuntimeException("The payroll for {$run->label()} has been approved and can no longer be prepared again.");
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $period);
        $elements = $this->elements();

        DB::transaction(function () use ($run, $start, $elements, $by) {
            $run->fill(['status' => 'draft', 'created_by' => $by->id])->save();
            $run->payslips()->delete();
            $problems = [];
            $totals = ['staff_count' => 0, 'gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'employer_cost' => 0.0];

            foreach ($this->staffFor($start) as $p) {
                $draft = $this->draftFor($p, $start, $elements);
                if (! $draft) {
                    $problems[] = "{$p->user->name}: no basic salary is set for {$p->payGrade()}" . ($p->step ? '' : ' (no step)') . '. Add it to the salary scale.';
                    continue;
                }
                if ($draft->factor <= 0) {
                    continue;
                }
                $notes = $draft->notes;
                if (! $p->bank_account_number) {
                    $notes[] = 'No bank account on record.';
                }
                if ($draft->pensionEmployee > 0 && (! $p->rsa_pin || ! $p->pfa_code)) {
                    $notes[] = 'Pension is deducted but the PFA or RSA PIN is missing.';
                }
                $slip = $run->payslips()->create([
                    'user_id' => $p->user_id, 'name' => $p->displayName(), 'staff_number' => $p->staff_number, 'department' => $p->workplace(),
                    'designation' => $p->designation, 'structure' => $p->salaryStructure?->code, 'grade_level' => $p->grade_level, 'step' => $p->step,
                    'factor' => $draft->factor, 'basic' => $draft->basic, 'gross' => $draft->gross, 'taxable' => $draft->taxable, 'tax' => $draft->tax,
                    'pension_employee' => $draft->pensionEmployee, 'pension_employer' => $draft->pensionEmployer, 'total_deductions' => $draft->totalDeductions,
                    'net' => $draft->net, 'bank_name' => $p->bank_name, 'bank_code' => $p->bank_code, 'bank_account_number' => $p->bank_account_number,
                    'bank_account_name' => $p->bank_account_name, 'pfa_code' => $p->pfa_code, 'rsa_pin' => $p->rsa_pin, 'tax_id' => $p->tax_id,
                    'tax_state' => $p->tax_state, 'notes' => $notes ?: null,
                ]);
                foreach ($draft->lines as $i => $l) {
                    $slip->lines()->create(['kind' => $l->kind, 'code' => $l->code, 'name' => $l->name, 'amount' => $l->amount, 'staff_pay_item_id' => $l->staffPayItemId, 'sort_order' => $i]);
                }
                $totals['staff_count']++;
                $totals['gross'] += $draft->gross;
                $totals['deductions'] += $draft->totalDeductions;
                $totals['net'] += $draft->net;
                $totals['employer_cost'] += $draft->gross + array_sum(array_map(fn ($l) => $l->amount, $draft->linesOf('employer')));
            }
            $run->update($totals + ['problems' => $problems ?: null]);
        });

        return $run->fresh();
    }

    /** Something used by the payroll changed after it was prepared, so what was checked is no longer what would be paid. */
    public function changedSince(PayrollRun $run): ?string
    {
        $at = $run->updated_at;
        $checks = [
            'a member of staff\'s record' => StaffProfile::where('updated_at', '>', $at),
            'a personal earning or deduction' => StaffPayItem::where('updated_at', '>', $at),
            'a pay element' => PayElement::where('updated_at', '>', $at),
            'the salary scale' => SalaryScale::where('updated_at', '>', $at),
            'the payroll settings' => \App\Models\Setting::where('key', 'like', 'payroll.%')->where('updated_at', '>', $at),
        ];
        foreach ($checks as $what => $q) {
            if ($q->exists()) {
                return $what;
            }
        }

        return null;
    }

    public function approve(PayrollRun $run, User $by): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new RuntimeException('Only a draft payroll can be approved.');
        }
        if ($what = $this->changedSince($run)) {
            throw new RuntimeException("{$what} changed after this payroll was prepared. Prepare it again, check it, then approve.");
        }
        if (! $run->payslips()->exists()) {
            throw new RuntimeException('This payroll has no payslips.');
        }
        if (PayrollRun::where('period', '<', $run->period)->where('status', 'draft')->exists()) {
            throw new RuntimeException('An earlier month is still a draft. Approve or delete it first, so loan balances are taken in order.');
        }

        DB::transaction(function () use ($run, $by) {
            $op = $this->audit->begin('payroll_approve', 'payroll', $by, null, null, ['period' => $run->period, 'run' => $run->id]);
            $loans = 0;
            foreach ($run->payslips()->with('lines')->get() as $slip) {
                foreach ($slip->lines->where('kind', 'deduction')->whereNotNull('staff_pay_item_id') as $line) {
                    $item = StaffPayItem::lockForUpdate()->find($line->staff_pay_item_id);
                    if (! $item || ! $item->isLoan()) {
                        continue;
                    }
                    $before = (float) $item->balance;
                    $after = round(max(0, $before - $line->amount), 2);
                    $item->timestamps = false;   // a balance going down is not a change to what was checked
                    $item->update(['balance' => $after]);
                    $this->audit->record($op, 'loan_repaid', $item, ['balance' => $before], ['balance' => $after], null, null, $slip->name . ' · ' . $item->name);
                    $loans++;
                }
            }
            $run->timestamps = false;
            $run->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()]);
            $this->audit->record($op, 'payroll_approved', $run, ['status' => 'draft'], ['status' => 'approved', 'net' => (string) $run->net, 'staff' => $run->staff_count], null, null, $run->label());
            $this->audit->finish($op, $run->staff_count);
        });

        $this->release($run->fresh());

        return $run->fresh();
    }

    /** Tell each member of staff their payslip is ready, and email it when that is switched on. */
    private function release(PayrollRun $run): void
    {
        $email = $this->settings->emailPayslips();
        foreach ($run->payslips()->with('user', 'lines')->get() as $slip) {
            $this->messenger->notify($slip->user, 'payslip', "Payslip for {$run->label()}", 'Net pay ₦' . number_format($slip->net, 2) . '. Open My Payslips to see the details.', $slip, route('my.payslips.show', $slip), 'info', "payslip:{$slip->id}");
            if ($email && $this->mailer->payslip($slip)) {
                $slip->update(['emailed_at' => now()]);
            }
        }
    }

    public function markPaid(PayrollRun $run, User $by): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw new RuntimeException('Only an approved payroll can be marked paid.');
        }
        $run->timestamps = false;
        $run->update(['status' => 'paid', 'paid_by' => $by->id, 'paid_at' => now()]);
        $this->audit->note('payroll_paid', 'payroll', $by, 'payroll_paid', $run, ['status' => 'approved'], ['status' => 'paid']);

        return $run->fresh();
    }

    public function discard(PayrollRun $run): void
    {
        if (! $run->isDraft()) {
            throw new RuntimeException('Only a draft payroll can be deleted.');
        }
        $run->delete();
    }
}
