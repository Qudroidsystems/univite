<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class InvoiceService
{
    /**
     * Build a student's fees for a session from the school bills: every active bill for their programme
     * and level that applies to them (fresh = entered in this session, otherwise returning).
     *
     * @return Invoice|null null when no bills are set for them (nothing to invoice)
     */
    public function generateForStudent(Student $student, AcademicSession $session, ?int $createdBy = null, ?int $semesterTypeId = null): ?Invoice
    {
        $existing = $this->sessionInvoice($student, $session, $semesterTypeId);
        if ($existing) {
            return $existing;
        }

        $matcher = new BillMatcher();
        $bills = $matcher->forStudent($student, $session, $semesterTypeId);

        if ($bills->isEmpty()) {
            return null;
        }

        $semester = $semesterTypeId ? \App\Models\SemesterType::find($semesterTypeId) : null;
        [$opens, $closes] = $matcher->window($bills);

        return DB::transaction(function () use ($student, $session, $bills, $createdBy, $matcher, $semester, $opens, $closes) {
            $invoice = Invoice::create([
                'semester_type_id'    => $semester?->id,
                'installment_plan'    => $matcher->plan($bills),
                'opens_at'            => $opens,
                'closes_at'           => $closes,
                'payable_type'        => Student::class,
                'payable_id'          => $student->id,
                'kind'                => 'fees',
                'academic_session_id' => $session->id,
                'title'               => "{$session->name}" . ($semester ? " {$semester->name}" : '') . " school fees (" . \App\Support\Levels::label((int) $student->current_level, $student->programme->studyCategory) . ')',
                'total'               => round($bills->sum(fn ($b) => (float) $b->amount), 2),
                'amount_paid'         => 0,
                'status'              => 'unpaid',
                'created_by'          => $createdBy,
            ]);
            $invoice->update(['reference' => sprintf('INV-%s-%06d', substr($session->name, 0, 4), $invoice->id)]);

            foreach ($bills as $bill) {
                $invoice->lines()->create(['bill_item_id' => $bill->bill_item_id, 'description' => $bill->item->name, 'amount' => $bill->amount]);
            }

            return $invoice;
        });
    }

    /**
     * Invoice every active student of the session who has none yet.
     *
     * @return array{created: int, existing: int, no_bills: int}
     */
    public function generateBulk(AcademicSession $session, ?int $programmeId = null, ?int $level = null, ?int $createdBy = null, ?int $semesterTypeId = null): array
    {
        $result = ['created' => 0, 'existing' => 0, 'no_bills' => 0];

        Student::whereIn('status', \App\Models\StudentStatus::codesWhere('is_enrolled'))
            ->when($programmeId, fn ($q) => $q->where('programme_id', $programmeId))
            ->when($level, fn ($q) => $q->where('current_level', $level))
            ->orderBy('id')
            ->each(function (Student $student) use ($session, $createdBy, $semesterTypeId, &$result) {
                if ($this->sessionInvoice($student, $session, $semesterTypeId)) {
                    $result['existing']++;
                } elseif ($this->generateForStudent($student, $session, $createdBy, $semesterTypeId)) {
                    $result['created']++;
                } else {
                    $result['no_bills']++;
                }
            });

        return $result;
    }

    /** A student's live (not cancelled) invoice for a session, if any. */
    public function sessionInvoice(Student $student, AcademicSession $session, ?int $semesterTypeId = null): ?Invoice
    {
        return Invoice::where('payable_type', Student::class)->where('payable_id', $student->id)
            ->where('kind', 'fees')->where('academic_session_id', $session->id)->where('semester_type_id', $semesterTypeId)
            ->where('status', '!=', 'cancelled')->first();
    }

    /**
     * The acceptance fee for a newly admitted student, from the bills for the bill item chosen in the
     * application settings (their programme, entry level and entry session). Null when none is configured.
     */
    public function generateAcceptanceInvoice(Student $student): ?Invoice
    {
        $existing = $student->invoices()->where('kind', 'acceptance')->where('status', '!=', 'cancelled')->first();
        $itemId = ApplicationSettings::acceptanceItemId();

        if ($existing || ! $itemId) {
            return $existing;
        }

        $bills = (new BillMatcher())->forStudent($student, $student->entrySession, null, true, $itemId, (int) $student->entry_level);

        if ($bills->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($student, $bills) {
            $session = $student->entrySession;
            $invoice = Invoice::create([
                'kind' => 'acceptance', 'payable_type' => Student::class, 'payable_id' => $student->id,
                'academic_session_id' => $session->id, 'title' => "{$session->name} acceptance fee",
                'total' => round($bills->sum(fn ($b) => (float) $b->amount), 2), 'amount_paid' => 0, 'status' => 'unpaid',
            ]);
            $invoice->update(['reference' => sprintf('INV-%s-%06d', substr($session->name, 0, 4), $invoice->id)]);
            foreach ($bills as $bill) {
                $invoice->lines()->create(['bill_item_id' => $bill->bill_item_id, 'description' => $bill->item->name, 'amount' => $bill->amount]);
            }

            return $invoice;
        });
    }

    /** Record money received outside the portal (bank teller, transfer, POS, cash). */
    public function recordOfflinePayment(Invoice $invoice, float $amount, string $channel, ?string $reference, ?string $paidOn, ?string $note, ?int $userId): Payment
    {
        return DB::transaction(function () use ($invoice, $amount, $channel, $reference, $paidOn, $note, $userId) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $amount = round($amount, 2);

            if ($invoice->status === 'cancelled') {
                throw new RuntimeException('This invoice has been cancelled.');
            }
            if ($amount <= 0) {
                throw new RuntimeException('The amount must be more than zero.');
            }
            if ($amount > $invoice->outstanding + 0.001) {
                throw new RuntimeException('That is more than the ₦' . number_format($invoice->outstanding, 2) . ' still owed on this invoice.');
            }

            $payment = Payment::create([
                'invoice_id'        => $invoice->id,
                'reference'         => 'PAY-' . strtoupper(Str::random(12)),
                'gateway'           => 'manual',
                'gateway_reference' => $reference ?: null,
                'amount'            => $amount,
                'status'            => 'successful',
                'channel'           => $channel,
                'paid_at'           => $paidOn ? \Illuminate\Support\Carbon::parse($paidOn)->setTimeFrom(now()) : now(),
                'recorded_by'       => $userId,
                'note'              => $note,
            ]);

            $this->refreshTotals($invoice);

            return $payment;
        });
    }

    /** Mark a pending online payment as successful (called by gateways) and update the invoice. */
    public function markSuccessful(Payment $payment, ?string $gatewayReference = null, ?array $payload = null): Payment
    {
        return DB::transaction(function () use ($payment, $gatewayReference, $payload) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === 'successful') {
                return $payment; // gateways may confirm the same payment twice (return + webhook)
            }

            $payment->update([
                'status' => 'successful', 'paid_at' => now(),
                'gateway_reference' => $gatewayReference ?? $payment->gateway_reference,
                'payload' => $payload ?? $payment->payload,
            ]);
            $this->refreshTotals(Invoice::lockForUpdate()->findOrFail($payment->invoice_id));

            return $payment;
        });
    }

    public function cancel(Invoice $invoice): void
    {
        if ((float) $invoice->amount_paid > 0) {
            throw new RuntimeException('This invoice already has payments recorded against it, so it cannot be cancelled.');
        }

        $invoice->update(['status' => 'cancelled']);
    }

    private function refreshTotals(Invoice $invoice): void
    {
        $paid = round((float) $invoice->payments()->where('status', 'successful')->sum('amount'), 2);

        $invoice->update([
            'amount_paid' => $paid,
            'status'      => $paid <= 0 ? 'unpaid' : ($paid + 0.001 >= (float) $invoice->total ? 'paid' : 'partial'),
        ]);

        // Paying fees can complete a student's clearance.
        if ($invoice->payable_type === Student::class && ($student = Student::find($invoice->payable_id))) {
            app(ClearanceService::class)->refresh($student);
        }
    }
}
