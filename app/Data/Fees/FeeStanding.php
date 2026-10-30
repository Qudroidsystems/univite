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
        public bool $showOwing = false,
        public ?string $owingNote = null,
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

    /** Everything still owed: this session's balance and anything left from earlier sessions. */
    public function owing(): float
    {
        return round($this->outstanding() + $this->arrears, 2);
    }

    /** Whether to remind the student of what they owe (on results, registration, the dashboard and the app). */
    public function remindOwing(): bool
    {
        return $this->showOwing && $this->owing() > 0.009;
    }

    public function toArray(): array
    {
        return [
            'session' => $this->session, 'total' => $this->total, 'paid' => $this->paid, 'paid_by_loan' => $this->loan, 'percent_paid' => $this->percent,
            'outstanding' => $this->outstanding(), 'arrears' => $this->arrears, 'loan_overdue' => $this->loanOverdue, 'billed' => $this->billed,
            'owing' => $this->owing(), 'show_owing' => $this->remindOwing(), 'owing_note' => $this->remindOwing() ? $this->owingNote : null,
            'access' => collect($this->areas)->map(fn ($a) => ['allowed' => $a['allowed'], 'reason' => $a['reason'], 'percent_needed' => $a['needed']])->all(),
        ];
    }
}
