<?php

namespace App\Services\Fees;

use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\LoanRepayment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentLoan;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\Messenger;
use App\Services\OperationAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The school fee loan scheme.
 *
 *  1. A student applies for a loan against the session's fees invoice (up to the share of the invoice the school allows),
 *     naming a guarantor when the school asks for one.
 *  2. The bursary approves it (amount, interest, instalments and first due date) or declines it.
 *  3. The approved loan is paid against the invoice as a "school loan" payment, so it counts as fees paid.
 *  4. Repayments are recorded until the loan is repaid; it can also be written off or cancelled before it is paid out.
 */
class LoanService
{
    public const DEFAULTS = [
        'loans.enabled' => '0',
        'loans.max_percent' => '50',          // of the invoice total
        'loans.interest_percent' => '0',      // flat, on the approved amount
        'loans.max_instalments' => '3',
        'loans.interval_days' => '30',
        'loans.first_due_days' => '30',
        'loans.require_guarantor' => '1',
        'loans.terms' => 'The loan is paid against your school fees. Repay each instalment by its due date; an overdue repayment can stop you registering, writing examinations or seeing your results.',
    ];

    public const REPAY_CHANNELS = ['bank_deposit' => 'Bank deposit (teller)', 'bank_transfer' => 'Bank transfer', 'pos' => 'POS', 'cash' => 'Cash at the bursary', 'salary' => 'Deducted from a sponsor\'s salary', 'other' => 'Other'];

    public function __construct(private InvoiceService $invoices, private OperationAudit $audit, private Messenger $messenger)
    {
    }

    public static function get(string $name): string
    {
        return (string) Setting::get('loans.' . $name, self::DEFAULTS['loans.' . $name]);
    }

    public static function enabled(): bool
    {
        return self::get('enabled') === '1';
    }

    public function saveSettings(array $v, User $by): void
    {
        $before = collect(self::DEFAULTS)->mapWithKeys(fn ($d, $k) => [$k => Setting::get($k, $d)])->all();
        Setting::put('loans.enabled', ! empty($v['enabled']) ? '1' : '0');
        Setting::put('loans.require_guarantor', ! empty($v['require_guarantor']) ? '1' : '0');
        foreach (['max_percent' => [1, 100], 'interest_percent' => [0, 100], 'max_instalments' => [1, 24], 'interval_days' => [7, 365], 'first_due_days' => [0, 365]] as $k => [$min, $max]) {
            if (isset($v[$k])) {
                Setting::put("loans.{$k}", (string) max($min, min($max, (float) $v[$k])));
            }
        }
        if (array_key_exists('terms', $v)) {
            Setting::put('loans.terms', trim((string) $v['terms']));
        }
        $after = collect(self::DEFAULTS)->mapWithKeys(fn ($d, $k) => [$k => Setting::get($k, $d)])->all();
        $this->audit->note('fee_access_rules', 'payments', $by, 'loan_policy_saved', null, $before, $after);
    }

    /** The session invoice a student could borrow against: the largest live fees invoice with money still owed. */
    public function invoiceFor(Student $student, ?AcademicSession $session = null): ?Invoice
    {
        $session ??= AcademicSession::current();

        return $session ? Invoice::where('payable_type', Student::class)->where('payable_id', $student->id)->where('kind', 'fees')
            ->where('academic_session_id', $session->id)->whereIn('status', ['unpaid', 'partial'])->orderByDesc('total')->first() : null;
    }

    /** The most the student may borrow against the invoice now. */
    public function maxAmount(Invoice $invoice): float
    {
        $cap = round((float) $invoice->total * (float) self::get('max_percent') / 100, 2);
        $already = (float) StudentLoan::where('invoice_id', $invoice->id)->whereIn('status', ['approved', 'disbursed', 'settled'])->sum('amount_approved');

        return max(0.0, round(min($cap - $already, $invoice->outstanding), 2));
    }

    /** Why the student cannot apply now, or null. */
    public function cannotApply(Student $student): ?string
    {
        if (! self::enabled()) {
            return 'The school loan scheme is not open.';
        }
        if (StudentLoan::where('student_id', $student->id)->whereIn('status', ['pending', 'approved'])->exists()) {
            return 'You already have a loan application waiting.';
        }
        if (($s = StudentLoan::where('student_id', $student->id)->where('status', 'disbursed')->get())->contains(fn ($l) => $l->overdue() > 0)) {
            return 'Repay your overdue loan instalment before applying again.';
        }
        $invoice = $this->invoiceFor($student);
        if (! $invoice) {
            return 'You have no unpaid fees invoice for this session to borrow against.';
        }
        if ($this->maxAmount($invoice) <= 0) {
            return 'You have already borrowed as much as the scheme allows against this invoice.';
        }

        return null;
    }

    public function apply(Student $student, array $d): StudentLoan
    {
        if ($why = $this->cannotApply($student)) {
            throw new RuntimeException($why);
        }
        $invoice = $this->invoiceFor($student);
        $amount = round((float) $d['amount'], 2);
        $max = $this->maxAmount($invoice);
        if ($amount <= 0 || $amount > $max + 0.001) {
            throw new RuntimeException('You can borrow up to ' . number_format($max, 2) . ' against this invoice.');
        }
        if (self::get('require_guarantor') === '1' && (blank($d['guarantor_name'] ?? null) || blank($d['guarantor_phone'] ?? null))) {
            throw new RuntimeException('Give your guarantor\'s name and phone number.');
        }

        $loan = StudentLoan::create([
            'student_id' => $student->id, 'academic_session_id' => $invoice->academic_session_id, 'invoice_id' => $invoice->id, 'amount_requested' => $amount,
            'reason' => $d['reason'] ?? null, 'guarantor_name' => $d['guarantor_name'] ?? null, 'guarantor_phone' => $d['guarantor_phone'] ?? null,
            'guarantor_relationship' => $d['guarantor_relationship'] ?? null, 'guarantor_address' => $d['guarantor_address'] ?? null, 'status' => 'pending',
        ]);
        $loan->update(['reference' => sprintf('LN-%s-%05d', substr((string) $invoice->session?->name, 0, 4) ?: now()->year, $loan->id)]);

        return $loan;
    }

    public function cancel(StudentLoan $loan, ?User $by = null, ?string $note = null): StudentLoan
    {
        if (! in_array($loan->status, ['pending', 'approved'], true)) {
            throw new RuntimeException('Only a loan that has not been paid out can be cancelled.');
        }
        $loan->update(['status' => 'cancelled', 'closed_by' => $by?->id, 'closed_at' => now(), 'close_note' => $note]);

        return $loan;
    }

    /** An even repayment plan: $count instalments, the first $firstDue, then every interval. */
    public function plan(float $due, int $count, Carbon $firstDue): array
    {
        $count = max(1, $count);
        $each = floor($due / $count * 100) / 100;
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $amount = $i === $count - 1 ? round($due - $each * ($count - 1), 2) : $each;
            $out[] = ['due' => $firstDue->copy()->addDays($i * (int) self::get('interval_days'))->toDateString(), 'amount' => $amount];
        }

        return $out;
    }

    public function approve(StudentLoan $loan, User $by, float $amount, int $instalments, ?string $firstDue, ?float $interest = null, ?string $note = null): StudentLoan
    {
        if ($loan->status !== 'pending') {
            throw new RuntimeException('This loan has already been decided.');
        }
        $invoice = $loan->invoice ?? throw new RuntimeException('The invoice this loan was for no longer exists.');
        $amount = round($amount, 2);
        $max = round(min((float) $invoice->total * (float) self::get('max_percent') / 100, $invoice->outstanding), 2);
        if ($amount <= 0 || $amount > $max + 0.001) {
            throw new RuntimeException('The approved amount must be between 0.01 and ' . number_format($max, 2) . '.');
        }
        if ($instalments < 1 || $instalments > (int) self::get('max_instalments')) {
            throw new RuntimeException('Choose between 1 and ' . self::get('max_instalments') . ' instalments.');
        }
        $rate = $interest ?? (float) self::get('interest_percent');
        $due = round($amount * (1 + $rate / 100), 2);
        $first = $firstDue ? Carbon::parse($firstDue) : today()->addDays((int) self::get('first_due_days'));
        $loan->update(['status' => 'approved', 'amount_approved' => $amount, 'interest_rate' => $rate, 'amount_due' => $due,
            'instalments' => $this->plan($due, $instalments, $first), 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);
        $this->audit->note('loan_decide', 'payments', $by, 'loan_approved', $loan, ['status' => 'pending'], ['status' => 'approved', 'amount' => $amount, 'due' => $due], $note, $loan->academic_session_id);
        $this->tell($loan, 'School loan approved', 'Your loan ' . $loan->reference . ' of ' . number_format($amount, 2) . ' has been approved. It will be paid against your fees shortly.', 'success');

        return $loan;
    }

    public function decline(StudentLoan $loan, User $by, string $note): StudentLoan
    {
        if ($loan->status !== 'pending') {
            throw new RuntimeException('This loan has already been decided.');
        }
        if (blank($note)) {
            throw new RuntimeException('Say why the loan is declined.');
        }
        $loan->update(['status' => 'declined', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);
        $this->audit->note('loan_decide', 'payments', $by, 'loan_declined', $loan, ['status' => 'pending'], ['status' => 'declined'], $note, $loan->academic_session_id);
        $this->tell($loan, 'School loan declined', "Your loan application {$loan->reference} was declined: {$note}", 'warning');

        return $loan;
    }

    /** Pay the approved loan onto the fees invoice. */
    public function disburse(StudentLoan $loan, User $by): StudentLoan
    {
        if ($loan->status !== 'approved') {
            throw new RuntimeException('Only an approved loan can be paid out.');
        }
        $invoice = $loan->invoice ?? throw new RuntimeException('The invoice this loan was for no longer exists.');
        $amount = min((float) $loan->amount_approved, $invoice->fresh()->outstanding);
        if ($amount <= 0) {
            throw new RuntimeException('The invoice is already fully paid, so there is nothing to pay the loan against. Cancel the loan instead.');
        }

        return DB::transaction(function () use ($loan, $by, $invoice, $amount) {
            $payment = $this->invoices->recordOfflinePayment($invoice, $amount, 'school_loan', $loan->reference, now()->toDateString(), 'School loan ' . $loan->reference, $by->id, 'loan');
            $loan->update(['status' => 'disbursed', 'disbursed_by' => $by->id, 'disbursed_at' => now(), 'payment_id' => $payment->id]);
            $this->audit->note('loan_disburse', 'payments', $by, 'loan_paid_against_fees', $loan, ['status' => 'approved'], ['status' => 'disbursed', 'payment' => $payment->reference, 'amount' => $amount], null, $loan->academic_session_id);
            $next = $loan->nextInstalment();
            $this->tell($loan, 'School loan paid against your fees', number_format($amount, 2) . " from loan {$loan->reference} has been paid against {$invoice->reference}." . ($next ? ' First repayment of ' . number_format($next['amount'], 2) . ' is due on ' . Carbon::parse($next['due'])->format('j M Y') . '.' : ''), 'success');

            return $loan->fresh();
        });
    }

    public function repay(StudentLoan $loan, User $by, float $amount, string $channel, ?string $paidOn, ?string $reference, ?string $note): LoanRepayment
    {
        if ($loan->status !== 'disbursed') {
            throw new RuntimeException('Repayments are recorded only on a loan that has been paid out and is not yet repaid.');
        }
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $loan->balance + 0.001) {
            throw new RuntimeException('The amount must be between 0.01 and the ' . number_format($loan->balance, 2) . ' still owed.');
        }

        return DB::transaction(function () use ($loan, $by, $amount, $channel, $paidOn, $reference, $note) {
            $row = $loan->repayments()->create(['amount' => $amount, 'paid_on' => $paidOn ?: today()->toDateString(), 'channel' => $channel, 'reference' => $reference, 'note' => $note, 'recorded_by' => $by->id]);
            $repaid = round((float) $loan->repayments()->sum('amount'), 2);
            $settled = $repaid + 0.001 >= (float) $loan->amount_due;
            $loan->update(['amount_repaid' => $repaid] + ($settled ? ['status' => 'settled', 'closed_at' => now(), 'closed_by' => $by->id] : []));
            $this->audit->note('loan_repayment', 'payments', $by, 'repayment_recorded', $loan, null, ['amount' => $amount, 'repaid' => $repaid, 'settled' => $settled], $note, $loan->academic_session_id);
            $this->tell($loan, 'Loan repayment received', 'We received ' . number_format($amount, 2) . " on loan {$loan->reference}. " . ($settled ? 'The loan is fully repaid.' : 'Still owed: ' . number_format($loan->fresh()->balance, 2) . '.'), 'success');

            return $row;
        });
    }

    public function writeOff(StudentLoan $loan, User $by, string $note): StudentLoan
    {
        if ($loan->status !== 'disbursed') {
            throw new RuntimeException('Only a loan being repaid can be written off.');
        }
        if (blank($note)) {
            throw new RuntimeException('Give the reason for writing the loan off.');
        }
        $balance = $loan->balance;
        $loan->update(['status' => 'written_off', 'closed_by' => $by->id, 'closed_at' => now(), 'close_note' => $note]);
        $this->audit->note('loan_writeoff', 'payments', $by, 'loan_written_off', $loan, ['status' => 'disbursed', 'balance' => $balance], ['status' => 'written_off'], $note, $loan->academic_session_id);

        return $loan;
    }

    private function tell(StudentLoan $loan, string $title, string $body, string $level): void
    {
        if ($user = $loan->student?->user) {
            $this->messenger->notify($user, 'fees', $title, $body, $loan, route('my.loans'), $level);
        }
    }
}
