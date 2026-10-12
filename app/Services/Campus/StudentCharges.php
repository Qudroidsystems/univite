<?php

namespace App\Services\Campus;

use App\Data\Campus\ChargeLine;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-off charges raised by campus services (accommodation, identity card replacement, library fines) as ordinary
 * invoices, so students pay them under My Fees through the same gateways and the bursary sees them with all the rest.
 */
class StudentCharges
{
    public const KINDS = ['accommodation' => 'Accommodation', 'id_card' => 'Identity card', 'library' => 'Library'];

    /** @param  ChargeLine[]  $lines */
    public function charge(Student $student, string $kind, string $title, array $lines, ?AcademicSession $session = null, ?User $by = null, ?\Carbon\CarbonInterface $dueDate = null): Invoice
    {
        $total = round(array_sum(array_map(fn (ChargeLine $l) => $l->amount, $lines)), 2);
        if ($total <= 0) {
            throw new RuntimeException('There is nothing to charge.');
        }
        $session ??= AcademicSession::current();

        return DB::transaction(function () use ($student, $kind, $title, $lines, $session, $by, $total, $dueDate) {
            $invoice = Invoice::create([
                'kind' => $kind, 'payable_type' => Student::class, 'payable_id' => $student->id, 'academic_session_id' => $session?->id,
                'title' => $title, 'total' => $total, 'amount_paid' => 0, 'status' => 'unpaid', 'created_by' => $by?->id, 'due_date' => $dueDate,
            ]);
            $invoice->update(['reference' => sprintf('INV-%s-%06d', $session ? substr($session->name, 0, 4) : now()->format('Y'), $invoice->id)]);
            foreach ($lines as $l) {
                $invoice->lines()->create(['bill_item_id' => $l->billItemId, 'description' => $l->description, 'amount' => round($l->amount, 2)]);
            }

            return $invoice;
        });
    }

    public function isPaid(?Invoice $invoice): bool
    {
        return $invoice !== null && $invoice->status === 'paid';
    }

    /** Cancel an unpaid charge that is no longer owed. A charge with any payment is left alone. */
    public function withdraw(?Invoice $invoice): bool
    {
        if (! $invoice || $invoice->status === 'cancelled' || (float) $invoice->amount_paid > 0) {
            return false;
        }
        $invoice->update(['status' => 'cancelled']);

        return true;
    }
}
