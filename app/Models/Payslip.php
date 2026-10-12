<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A member of staff's pay for one month, with the details used to pay them copied in so it never changes later. */
class Payslip extends Model
{
    protected $fillable = [
        'payroll_run_id', 'user_id', 'name', 'staff_number', 'department', 'designation', 'structure', 'grade_level', 'step', 'factor',
        'basic', 'gross', 'taxable', 'tax', 'pension_employee', 'pension_employer', 'total_deductions', 'net',
        'bank_name', 'bank_code', 'bank_account_number', 'bank_account_name', 'pfa_code', 'rsa_pin', 'tax_id', 'tax_state', 'notes', 'emailed_at',
    ];

    protected $casts = [
        'factor' => 'float', 'basic' => 'float', 'gross' => 'float', 'taxable' => 'float', 'tax' => 'float', 'pension_employee' => 'float',
        'pension_employer' => 'float', 'total_deductions' => 'float', 'net' => 'float', 'notes' => 'array', 'emailed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sort_order');
    }

    /** "Prof A. Bello · SS/1234" */
    public function heading(): string
    {
        return $this->name . ($this->staff_number ? ' · ' . $this->staff_number : '');
    }
}
