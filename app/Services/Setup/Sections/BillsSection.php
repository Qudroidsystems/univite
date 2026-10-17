<?php

namespace App\Services\Setup\Sections;

use App\Data\Setup\RestorePlan;
use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\Invoice;
use App\Services\Setup\RowSync;
use App\Services\Setup\SetupSection;

/** The session's school bills (fee schedule). A bill added since is switched off rather than removed once invoices exist. */
class BillsSection implements SetupSection
{
    public function key(): string
    {
        return 'bills';
    }

    public function label(): string
    {
        return 'School bills';
    }

    public function about(): string
    {
        return 'The session\'s fee schedule: every bill with its amount, who pays it, instalments and payment window.';
    }

    public function perSession(): bool
    {
        return true;
    }

    private function sync(AcademicSession $session): RowSync
    {
        $invoiced = Invoice::where('academic_session_id', $session->id)->exists();

        return new RowSync(Bill::class, fn () => Bill::where('academic_session_id', $session->id), (new Bill())->getFillable(),
            function (Bill $bill, bool $apply) use ($invoiced) {
                if (! $invoiced) {
                    return null;
                }
                $apply && $bill->is_active && $bill->update(['is_active' => false]);

                return 'Invoices exist for this session, so bills added since were switched off instead of removed.';
            });
    }

    public function capture(?AcademicSession $session): array
    {
        return $session ? $this->sync($session)->capture() : [];
    }

    public function count(array $data): int
    {
        return count($data);
    }

    public function restore(array $data, ?AcademicSession $session, bool $apply): RestorePlan
    {
        return $session ? $this->sync($session)->restore($data, $apply) : new RestorePlan();
    }
}
