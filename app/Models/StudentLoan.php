<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A school fee loan: applied for, approved (or declined), paid onto the fees invoice, then repaid in instalments. */
class StudentLoan extends Model
{
    public const STATUSES = [
        'pending' => 'Awaiting decision', 'approved' => 'Approved, not yet paid', 'declined' => 'Declined', 'disbursed' => 'Paid against fees · repaying',
        'settled' => 'Repaid', 'written_off' => 'Written off', 'cancelled' => 'Cancelled',
    ];

    public const BADGES = [
        'pending' => 'bg-warning-subtle text-warning', 'approved' => 'bg-info-subtle text-info', 'declined' => 'bg-danger-subtle text-danger',
        'disbursed' => 'bg-primary-subtle text-primary', 'settled' => 'bg-success-subtle text-success', 'written_off' => 'bg-secondary-subtle text-secondary',
        'cancelled' => 'bg-secondary-subtle text-secondary',
    ];

    /** Loans that are still owed or about to be. */
    public const LIVE = ['pending', 'approved', 'disbursed'];

    protected $fillable = [
        'reference', 'student_id', 'academic_session_id', 'invoice_id', 'amount_requested', 'amount_approved', 'interest_rate', 'amount_due', 'amount_repaid',
        'status', 'reason', 'guarantor_name', 'guarantor_phone', 'guarantor_relationship', 'guarantor_address', 'instalments',
        'decided_by', 'decided_at', 'decision_note', 'disbursed_by', 'disbursed_at', 'payment_id', 'closed_by', 'closed_at', 'close_note',
    ];

    protected $casts = [
        'amount_requested' => 'decimal:2', 'amount_approved' => 'decimal:2', 'interest_rate' => 'decimal:2', 'amount_due' => 'decimal:2', 'amount_repaid' => 'decimal:2',
        'instalments' => 'array', 'decided_at' => 'datetime', 'disbursed_at' => 'datetime', 'closed_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'pending', 'amount_repaid' => 0, 'amount_due' => 0, 'interest_rate' => 0];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class)->latest('paid_on')->latest('id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function getBalanceAttribute(): float
    {
        return in_array($this->status, ['disbursed'], true) ? max(0.0, round((float) $this->amount_due - (float) $this->amount_repaid, 2)) : 0.0;
    }

    /** What should have been repaid by a date under the instalment plan. */
    public function dueBy(?\DateTimeInterface $date = null): float
    {
        $date = $date ? \Illuminate\Support\Carbon::instance($date) : today();

        return round(collect($this->instalments ?? [])->filter(fn ($i) => \Illuminate\Support\Carbon::parse($i['due'])->lte($date))->sum('amount'), 2);
    }

    /** Amount past due and unpaid today (0 when up to date). */
    public function overdue(): float
    {
        if ($this->status !== 'disbursed') {
            return 0.0;
        }

        return max(0.0, round($this->dueBy() - (float) $this->amount_repaid, 2));
    }

    /** The next instalment not yet covered by repayments: [due date, amount still to pay on it]. */
    public function nextInstalment(): ?array
    {
        $paid = (float) $this->amount_repaid;
        $running = 0.0;
        foreach ($this->instalments ?? [] as $i) {
            $running += (float) $i['amount'];
            if ($running - $paid > 0.009) {
                return ['due' => $i['due'], 'amount' => round(min((float) $i['amount'], $running - $paid), 2)];
            }
        }

        return null;
    }
}
