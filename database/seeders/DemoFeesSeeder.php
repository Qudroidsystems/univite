<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\Demo\DemoPeople;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Sample data, part 5: the fee schedule for every session, each student's school-fees invoice for every session since
 * they entered, and payments recorded at the bursary. Past sessions are fully paid (some in two instalments); this
 * session is a mix of paid, part-paid and unpaid so the debtors and collection reports have something to show.
 * Payments go through InvoiceService, so receipts, the audit trail and "payment received" notices are all real; mail
 * is switched to the in-memory mailer while seeding so no email leaves the computer.
 */
class DemoFeesSeeder extends Seeder
{
    /** code => [name, amount, fresh only?, compulsory?] */
    private const ITEMS = [
        'TUI' => ['Tuition', 45000, false, true],
        'REG' => ['Registration', 7500, false, true],
        'DEV' => ['Development levy', 15000, false, true],
        'ICT' => ['ICT and e-learning', 8000, false, true],
        'LIB' => ['Library', 3500, false, true],
        'MED' => ['Medical and health insurance', 5000, false, true],
        'SPT' => ['Sports', 2000, false, true],
        'EXM' => ['Examination', 6000, false, true],
        'MAT' => ['Matriculation', 5000, true, true],
        'IDC' => ['Identity card', 2500, true, true],
        'LAB' => ['Laboratory (Science)', 12000, false, true],
    ];

    private const CHANNELS = ['bank_deposit', 'bank_deposit', 'bank_transfer', 'pos'];

    private const BANKS = ['FBN', 'GTB', 'UBA', 'ZEN', 'WEMA', 'ACC'];

    public function run(InvoiceService $invoices): void
    {
        config(['mail.default' => 'array']);
        $people = new DemoPeople(5501);
        $bursar = User::role('Bursar')->orderBy('id')->first();
        $sessions = AcademicSession::orderBy('name')->get()->values();
        $current = AcademicSession::current() ?? $sessions->last();
        $science = Faculty::where('code', 'SCI')->first();

        $items = [];
        foreach (self::ITEMS as $code => [$name, $amount, $freshOnly]) {
            $items[$code] = BillItem::firstOrCreate(['code' => $code], ['name' => $name, 'is_compulsory' => true, 'is_active' => true]);
        }

        foreach ($sessions as $i => $session) {
            // Fees rise a little each session.
            $rise = 1 + 0.05 * $i;
            foreach (self::ITEMS as $code => [$name, $amount, $freshOnly]) {
                $scienceOnly = $code === 'LAB';
                if ($scienceOnly && ! $science) {
                    continue;
                }
                Bill::firstOrCreate([
                    'academic_session_id' => $session->id,
                    'bill_item_id'        => $items[$code]->id,
                    'faculty_id'          => $scienceOnly ? $science->id : null,
                ], [
                    'student_type' => $freshOnly ? 'fresh' : 'all',
                    'nationality'  => 'any',
                    'amount'       => round($amount * $rise, -2),
                    'installments' => $code === 'TUI' ? 2 : 1,
                    'installment_percents' => $code === 'TUI' ? [60, 40] : null,
                    'is_active'    => true,
                ]);
            }
        }

        foreach (Student::where('needs_onboarding', false)->with('programme.studyCategory')->orderBy('id')->get() as $student) {
            $entryIndex = $sessions->search(fn ($s) => $s->id === $student->academic_session_id);
            $finalLevel = (int) $student->current_level;
            $currentIndex = $sessions->search(fn ($s) => $s->id === $current->id);

            for ($si = $entryIndex; $si <= $currentIndex; $si++) {
                $session = $sessions[$si];
                $start = $this->sessionStart($session);
                // The level the student was in that session (bills can vary by level).
                $student->current_level = $finalLevel - 100 * ($currentIndex - $si);
                $invoice = $invoices->generateForStudent($student, $session, $bursar?->id);
                $student->current_level = $finalLevel;
                if (! $invoice) {
                    continue;
                }
                Invoice::whereKey($invoice->id)->update(['created_at' => $start->copy()->addWeeks(2), 'updated_at' => $start->copy()->addWeeks(2)]);

                $total = (float) $invoice->total;
                $isCurrent = $si === $currentIndex;
                $roll = $people->int(1, 100);
                if ($isCurrent && $roll <= 25) {
                    continue; // not paid yet
                }
                $first = round($total * 0.6, 2);
                $amounts = $isCurrent && $roll <= 45 ? [$first] : ($people->chance(30) ? [$first, $total - $first] : [$total]);
                $paidOn = $start->copy()->addWeeks($people->int(1, 8));
                foreach ($amounts as $amount) {
                    $invoices->recordOfflinePayment(
                        $invoice->fresh(),
                        $amount,
                        $people->pick(self::CHANNELS),
                        $people->pick(self::BANKS) . '-' . $people->int(10000000, 99999999),
                        $paidOn->min(now())->toDateString(),
                        null,
                        $bursar?->id,
                    );
                    $paidOn->addWeeks($people->int(6, 12));
                }
            }
        }
    }

    /** Sessions are named like 2025/2026 and start in October of the first year. */
    private function sessionStart(AcademicSession $session): Carbon
    {
        return Carbon::create((int) substr($session->name, 0, 4), 10, 1);
    }
}
