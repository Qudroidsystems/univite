<?php

namespace App\Data\Fees;

/**
 * Where a student stands on the session's fees, and what that lets them do.
 *
 * @property-read array<string, array{allowed: bool, reason: ?string, needed: ?int, override: ?string}> $areas
 */
final readonly class FeeStanding
{
    public function __construct(
        public ?string $session,
        public float $total,
        public float $paid,
        public float $loan,
        public float $percent,
        public float $arrears,
        public float $loanOverdue,
        public bool $billed,
        public array $areas,
    ) {
    }

    public function allows(string $area): bool
    {
        return $this->areas[$area]['allowed'] ?? true;
    }

    public function reason(string $area): ?string
    {
        return $this->areas[$area]['reason'] ?? null;
    }

    public function outstanding(): float
    {
        return max(0.0, round($this->total - $this->paid, 2));
    }

    public function toArray(): array
    {
        return [
            'session' => $this->session, 'total' => $this->total, 'paid' => $this->paid, 'paid_by_loan' => $this->loan, 'percent_paid' => $this->percent,
            'outstanding' => $this->outstanding(), 'arrears' => $this->arrears, 'loan_overdue' => $this->loanOverdue, 'billed' => $this->billed,
            'access' => collect($this->areas)->map(fn ($a) => ['allowed' => $a['allowed'], 'reason' => $a['reason'], 'percent_needed' => $a['needed']])->all(),
        ];
    }
}
