<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An allowance, deduction or employer contribution on every payslip it applies to.
 * How the amount is worked out (method): a fixed monthly amount, a percentage of basic salary, a percentage of
 * pensionable pay, an amount per grade level, or income tax (PAYE) from the tax table.
 */
class PayElement extends Model
{
    public const KINDS = ['earning' => 'Earning (allowance)', 'deduction' => 'Deduction', 'employer' => 'Employer contribution (not deducted)'];

    public const METHODS = [
        'fixed' => 'Fixed amount each month',
        'percent_basic' => 'Percentage of basic salary',
        'percent_pensionable' => 'Percentage of pensionable pay',
        'grade_table' => 'Amount set for each grade level',
        'paye' => 'Income tax (PAYE) from the tax table',
    ];

    protected $fillable = ['code', 'name', 'kind', 'method', 'amount', 'rate', 'taxable', 'pensionable', 'tax_relief', 'applies_to', 'grade_amounts', 'sort_order', 'is_active', 'is_system', 'description'];

    protected $casts = [
        'amount' => 'float', 'rate' => 'float', 'taxable' => 'boolean', 'pensionable' => 'boolean', 'tax_relief' => 'boolean',
        'applies_to' => 'array', 'grade_amounts' => 'array', 'is_active' => 'boolean', 'is_system' => 'boolean',
    ];

    /** Whether this element applies to a member of staff (structure, category and grade-level limits). */
    public function appliesTo(StaffProfile $p): bool
    {
        $a = $this->applies_to ?? [];
        $structure = $p->salaryStructure?->code;

        return (empty($a['structures']) || in_array($structure, $a['structures'], true))
            && (empty($a['categories']) || in_array($p->category, $a['categories'], true))
            && (empty($a['grade_min']) || (int) $p->grade_level >= (int) $a['grade_min'])
            && (empty($a['grade_max']) || (int) $p->grade_level <= (int) $a['grade_max']);
    }

    public function describe(): string
    {
        return match ($this->method) {
            'fixed' => '₦' . number_format((float) $this->amount, 2) . ' a month',
            'percent_basic' => rtrim(rtrim(number_format((float) $this->rate, 3), '0'), '.') . '% of basic',
            'percent_pensionable' => rtrim(rtrim(number_format((float) $this->rate, 3), '0'), '.') . '% of pensionable pay',
            'grade_table' => 'By grade level (' . count($this->grade_amounts ?? []) . ' set)',
            'paye' => 'Tax table',
            default => $this->method,
        };
    }
}
