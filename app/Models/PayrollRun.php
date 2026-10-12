<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One month's payroll: prepared as a draft, approved (payslips released to staff), then marked paid. */
class PayrollRun extends Model
{
    public const STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'paid' => 'Paid'];
    public const BADGES = ['draft' => 'bg-warning-subtle text-warning', 'approved' => 'bg-primary-subtle text-primary', 'paid' => 'bg-success-subtle text-success'];

    protected $fillable = ['period', 'status', 'staff_count', 'gross', 'deductions', 'net', 'employer_cost', 'problems', 'note', 'created_by', 'approved_by', 'approved_at', 'paid_by', 'paid_at'];

    protected $casts = ['problems' => 'array', 'approved_at' => 'datetime', 'paid_at' => 'datetime', 'gross' => 'float', 'deductions' => 'float', 'net' => 'float', 'employer_cost' => 'float'];

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->period);
    }

    public function label(): string
    {
        return $this->start()->format('F Y');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
