<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member of staff's employment record: where they work (department or unit), category, grade and step on a salary
 * structure, appointment and service dates, how they are paid (bank), their pension (PFA and RSA PIN) and tax details,
 * and, when they leave, the exit date and reason. Leave rules, approval routes, payroll and pensions all read it.
 */
class StaffProfile extends Model
{
    protected $fillable = [
        'user_id', 'title', 'staff_number', 'department_id', 'unit_id', 'designation', 'category', 'appointment_type', 'grade_level',
        'salary_structure_id', 'step', 'employment_status', 'first_appointment_date', 'confirmation_date', 'professorial', 'exit_date', 'exit_reason',
        'supervisor_id', 'critical_role', 'bank_name', 'bank_code', 'bank_account_number', 'bank_account_name', 'pfa_code', 'rsa_pin',
        'tax_id', 'tax_state', 'nhf_number',
        'file_number', 'ippis_number', 'id_card_number', 'payslip_number', 'institutional_email', 'signature',
    ];

    protected $casts = [
        'first_appointment_date' => 'date', 'confirmation_date' => 'date', 'exit_date' => 'date', 'critical_role' => 'boolean', 'professorial' => 'boolean',
        'grade_level' => 'integer', 'step' => 'integer',
    ];

    /** Pay and pension details: shown and changed only by those allowed to see pay. */
    public const PAY_FIELDS = ['salary_structure_id', 'step', 'bank_name', 'bank_code', 'bank_account_number', 'bank_account_name', 'pfa_code', 'rsa_pin', 'tax_id', 'tax_state', 'nhf_number'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function salaryStructure(): BelongsTo
    {
        return $this->belongsTo(SalaryStructure::class);
    }

    public function serviceMonths(?\Carbon\CarbonInterface $on = null): int
    {
        return $this->first_appointment_date ? (int) $this->first_appointment_date->diffInMonths($on ?? now()) : 0;
    }

    /** "Prof Adaeze Bello" */
    public function displayName(): string
    {
        return trim(($this->title ? \App\Support\Lookups::label('title', $this->title) . ' ' : '') . ($this->user?->name ?? ''));
    }

    /** Department, or unit for non-teaching offices. */
    public function workplace(): ?string
    {
        return $this->department?->name ?? $this->unit?->name;
    }

    /** "CONUASS 4/3" */
    public function payGrade(): ?string
    {
        if (! $this->grade_level) {
            return null;
        }

        return trim(($this->salaryStructure?->code ?? 'GL') . ' ' . $this->grade_level . ($this->step ? '/' . $this->step : ''));
    }
}
