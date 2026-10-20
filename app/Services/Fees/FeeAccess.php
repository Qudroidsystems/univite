<?php

namespace App\Services\Fees;

use App\Data\Fees\FeeStanding;
use App\Models\AcademicSession;
use App\Models\FeeAccessOverride;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentLoan;
use App\Models\User;
use App\Services\OperationAudit;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The share of a session's fees a student must have paid before they may register courses, write examinations, see
 * their results or use the portal and app at all. Each area is switched on separately with its own percentage. School
 * loans paid against the invoice count as paid (unless the school says otherwise); an overdue loan repayment can bar
 * the student too. Staff can allow (or bar) one student for one area for the session, with a reason.
 *
 * A student with nothing billed for the session is never held back: there is nothing to pay.
 */
class FeeAccess
{
    public const AREAS = [
        'registration' => ['Course registration', 'register courses'],
        'exams' => ['Writing examinations', 'write examinations (print the examination slip, be checked in at the hall)'],
        'results' => ['Viewing results', 'see results'],
        'portal' => ['The whole portal and mobile app', 'use the portal and the app (only fees, payments and loans stay open)'],
    ];

    public const DEFAULTS = [
        'fee_access.registration_on' => '0', 'fee_access.registration_percent' => '50',
        'fee_access.exams_on' => '0', 'fee_access.exams_percent' => '100',
        'fee_access.results_on' => '0', 'fee_access.results_percent' => '100',
        'fee_access.portal_on' => '0', 'fee_access.portal_percent' => '25',
        'fee_access.count_loans' => '1',          // a school loan paid onto the invoice counts as fees paid
        'fee_access.loan_overdue_blocks' => '1',  // an overdue loan repayment bars every area that is switched on
        'fee_access.include_arrears' => '0',      // money still owed from earlier sessions bars every area that is switched on
    ];

    /** @var array<string, FeeStanding> */
    private array $cache = [];

    public function __construct(private OperationAudit $audit)
    {
    }

    public static function get(string $name): string
    {
        return Setting::get('fee_access.' . $name, self::DEFAULTS['fee_access.' . $name]);
    }

    public static function on(string $name): bool
    {
        return self::get($name) === '1';
    }

    public static function areaOn(string $area): bool
    {
        return self::on("{$area}_on");
    }

    public static function percentFor(string $area): int
    {
        return max(0, min(100, (int) self::get("{$area}_percent")));
    }

    public static function anyOn(): bool
    {
        return collect(array_keys(self::AREAS))->contains(fn ($a) => self::areaOn($a));
    }

    public function save(array $values, User $by): void
    {
        $before = $this->settings();
        foreach (array_keys(self::DEFAULTS) as $key) {
            $name = substr($key, 11);
            if (str_ends_with($name, '_percent')) {
                Setting::put($key, (string) max(0, min(100, (int) ($values[$name] ?? self::DEFAULTS[$key]))));
            } else {
                Setting::put($key, ! empty($values[$name]) ? '1' : '0');
            }
        }
        $this->cache = [];
        $this->audit->note('fee_access_rules', 'payments', $by, 'rules_saved', null, $before, $this->settings());
    }

    public function settings(): array
    {
        return collect(self::DEFAULTS)->mapWithKeys(fn ($v, $k) => [substr($k, 11) => Setting::get($k, $v)])->all();
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** The student's standing for a session (the current one by default). */
    public function standing(Student $student, ?AcademicSession $session = null): FeeStanding
    {
        $session ??= AcademicSession::current();
        // Cached for one request only: controllers (and so this service) can outlive a request.
        $key = spl_object_id(request()) . ':' . $student->id . ':' . ($session?->id ?? 0);
        if (count($this->cache) > 500) {
            $this->cache = [];
        }

        return $this->cache[$key] ??= $this->work($student, $session);
    }

    private function work(Student $student, ?AcademicSession $session): FeeStanding
    {
        $invoices = $session ? Invoice::where('payable_type', Student::class)->where('payable_id', $student->id)->where('kind', 'fees')
            ->where('academic_session_id', $session->id)->where('status', '!=', 'cancelled')->get() : collect();
        $total = round((float) $invoices->sum('total'), 2);
        $paid = round((float) $invoices->sum('amount_paid'), 2);
        $loan = $invoices->isEmpty() ? 0.0 : round((float) Payment::whereIn('invoice_id', $invoices->pluck('id'))->where('gateway', 'loan')->where('status', 'successful')->sum('amount'), 2);
        $counted = self::on('count_loans') ? $paid : max(0.0, $paid - $loan);
        $percent = $total > 0 ? round($counted / $total * 100, 1) : 100.0;
        $arrears = $session ? round((float) Invoice::where('payable_type', Student::class)->where('payable_id', $student->id)->where('kind', 'fees')
            ->where('status', '!=', 'cancelled')->where('academic_session_id', '!=', $session->id)
            ->whereHas('session', fn ($q) => $q->where('name', '<', $session->name))->get()->sum(fn ($i) => $i->outstanding), 2) : 0.0;
        $overdue = round((float) StudentLoan::where('student_id', $student->id)->where('status', 'disbursed')->get()->sum(fn ($l) => $l->overdue()), 2);
        $overrides = $session ? FeeAccessOverride::where('student_id', $student->id)->where('academic_session_id', $session->id)->get()->filter->inForce()->keyBy('area') : collect();
        $billed = $total > 0;

        $areas = [];
        foreach (self::AREAS as $area => [$label, $verb]) {
            $needed = self::areaOn($area) ? self::percentFor($area) : null;
            $reason = null;
            if ($needed !== null && $billed) {
                if ($percent + 0.001 < $needed) {
                    $reason = "You have paid {$this->pct($percent)}% of your {$session->name} fees; {$needed}% is needed to {$verb}.";
                } elseif (self::on('include_arrears') && $arrears > 0.009) {
                    $reason = 'You still owe ' . number_format($arrears, 2) . " from earlier sessions. Clear it to {$verb}.";
                } elseif (self::on('loan_overdue_blocks') && $overdue > 0.009) {
                    $reason = 'A school loan repayment of ' . number_format($overdue, 2) . " is overdue. Pay it to {$verb}.";
                }
            }
            $o = $overrides->get($area);
            $override = null;
            if ($o) {
                $override = ($o->allow ? 'Allowed by the school: ' : 'Barred by the school: ') . $o->reason;
                $reason = $o->allow ? null : 'The school has stopped you from doing this for now: ' . $o->reason;
            }
            $areas[$area] = ['allowed' => $reason === null, 'reason' => $reason, 'needed' => $needed, 'override' => $override];
        }

        return new FeeStanding($session?->name, $total, $paid, $loan, $percent, $arrears, $overdue, $billed, $areas);
    }

    private function pct(float $p): string
    {
        return rtrim(rtrim(number_format($p, 1), '0'), '.');
    }

    /** Why the student may not do this now, or null. */
    public function blocker(Student $student, string $area, ?AcademicSession $session = null): ?string
    {
        return $this->standing($student, $session)->reason($area);
    }

    public function override(Student $student, AcademicSession $session, string $area, ?bool $allow, ?string $reason, User $by, ?string $expiresOn = null): ?FeeAccessOverride
    {
        if (! isset(self::AREAS[$area])) {
            throw new RuntimeException('Unknown area.');
        }
        $existing = FeeAccessOverride::where('student_id', $student->id)->where('academic_session_id', $session->id)->where('area', $area)->first();
        $before = $existing ? ['allow' => $existing->allow, 'reason' => $existing->reason] : null;
        if ($allow === null) {
            $existing?->delete();
            $row = null;
        } else {
            if (blank($reason)) {
                throw new RuntimeException('Give the reason.');
            }
            $row = FeeAccessOverride::updateOrCreate(['student_id' => $student->id, 'academic_session_id' => $session->id, 'area' => $area],
                ['allow' => $allow, 'reason' => $reason, 'expires_on' => $expiresOn ?: null, 'created_by' => $by->id]);
        }
        $this->cache = [];
        $this->audit->note('fee_access_override', 'payments', $by, $allow === null ? 'override_removed' : ($allow ? 'allowed' : 'barred'), $student, $before,
            $row ? ['area' => $area, 'allow' => $row->allow, 'reason' => $row->reason] : ['area' => $area], $reason, $session->id);

        return $row;
    }

    /** @param  Collection<int, Student>  $students @return Collection<int, array{student: Student, standing: FeeStanding}> */
    public function list(Collection $students, ?AcademicSession $session = null): Collection
    {
        return $students->map(fn (Student $s) => ['student' => $s, 'standing' => $this->standing($s, $session)]);
    }
}
