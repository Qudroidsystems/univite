<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Leave\PolicyCrud;
use App\Models\PayElement;
use App\Models\SalaryStructure;
use App\Support\Lookups;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/** Allowances, deductions and employer contributions that go on every payslip they apply to. */
class PayElementController extends PolicyCrud
{
    protected string $permission = 'pay elements';
    protected string $gate = 'Set payroll policy';
    protected array $viewers = ['View payroll'];
    protected string $title = 'Allowances & Deductions';
    protected string $singular = 'Pay item';
    protected string $icon = 'ri-list-settings-line';
    protected string $routeBase = 'payroll.elements';
    protected string $intro = 'Items on every payslip they apply to. Basic salary comes from the salary scale. Mark the allowances that count towards pension (usually housing and transport) and those that are taxable. Deductions marked "before tax" (pension, NHF) are taken off income before tax is worked out. Personal items such as loans are added on each staff record.';
    protected int $perPage = 50;

    protected function model(): string
    {
        return PayElement::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderBy('sort_order')->orderBy('name');
    }

    protected function filters(): array
    {
        return [['kind', 'Kind', PayElement::KINDS]];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]+$/', Rule::unique('pay_elements', 'code')->ignore($record?->id), Rule::notIn(['BASIC', 'basic'])],
            'name' => ['required', 'string', 'max:120', Rule::unique('pay_elements', 'name')->ignore($record?->id)], 'kind' => ['required', Rule::in(array_keys(PayElement::KINDS))],
            'method' => ['required', Rule::in(array_keys(PayElement::METHODS))], 'amount' => ['nullable', 'required_if:method,fixed', 'numeric', 'min:0'],
            'rate' => ['nullable', 'required_if:method,percent_basic,percent_pensionable', 'numeric', 'between:0,100'],
            'taxable' => ['nullable', 'boolean'], 'pensionable' => ['nullable', 'boolean'], 'tax_relief' => ['nullable', 'boolean'],
            'applies_structures' => ['nullable', 'string', 'max:255'], 'applies_categories' => ['nullable', 'string', 'max:255'],
            'grade_min' => ['nullable', 'integer', 'between:1,20'], 'grade_max' => ['nullable', 'integer', 'between:1,20'],
            'grade_amounts_text' => ['nullable', 'required_if:method,grade_table', 'string', 'max:2000', 'regex:/^(\s*\d{1,2}\s*[:=]\s*[\d,.]+\s*(\r?\n|$))+$/'],
            'sort_order' => ['nullable', 'integer', 'between:0,999'], 'is_active' => ['nullable', 'boolean'], 'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'required' => true, 'col' => 3, 'placeholder' => 'HOUSING'],
            ['name' => 'name', 'label' => 'Name on the payslip', 'required' => true, 'col' => 5, 'placeholder' => 'Rent allowance'],
            ['name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => PayElement::KINDS, 'required' => true, 'col' => 4],
            ['name' => 'method', 'label' => 'Worked out as', 'type' => 'select', 'options' => PayElement::METHODS, 'required' => true, 'col' => 6],
            ['name' => 'amount', 'label' => 'Amount a month (₦)', 'type' => 'number', 'step' => '0.01', 'col' => 3, 'showWhen' => ['method', 'fixed']],
            ['name' => 'rate', 'label' => 'Percentage', 'type' => 'number', 'step' => '0.001', 'col' => 3, 'help' => 'For example 8 for 8%.'],
            ['name' => 'grade_amounts_text', 'label' => 'Amount a month for each grade level', 'type' => 'textarea', 'transient' => true, 'showWhen' => ['method', 'grade_table'], 'help' => 'One line per grade: 7: 15000. A grade not listed gets nothing.'],
            ['name' => 'taxable', 'label' => 'Taxable (earnings)', 'type' => 'switch', 'default' => true, 'col' => 4],
            ['name' => 'pensionable', 'label' => 'Counts towards pension (earnings)', 'type' => 'switch', 'col' => 4],
            ['name' => 'tax_relief', 'label' => 'Taken off before tax (deductions)', 'type' => 'switch', 'col' => 4],
            ['name' => 'applies_structures', 'label' => 'Only these structures', 'transient' => true, 'col' => 6, 'placeholder' => 'Blank = all', 'help' => 'Codes separated by commas: ' . SalaryStructure::orderBy('code')->pluck('code')->implode(', ')],
            ['name' => 'applies_categories', 'label' => 'Only these staff categories', 'transient' => true, 'col' => 6, 'placeholder' => 'Blank = all', 'help' => 'Codes separated by commas: ' . implode(', ', array_keys(Lookups::options('staff_category')))],
            ['name' => 'grade_min', 'label' => 'From grade level', 'type' => 'number', 'transient' => true, 'col' => 3],
            ['name' => 'grade_max', 'label' => 'To grade level', 'type' => 'number', 'transient' => true, 'col' => 3],
            ['name' => 'sort_order', 'label' => 'Order on payslip', 'type' => 'number', 'col' => 3, 'default' => 10],
            ['name' => 'is_active', 'label' => 'In use', 'type' => 'switch', 'default' => true, 'col' => 3],
            ['name' => 'description', 'label' => 'Notes', 'type' => 'textarea'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $list = fn ($v) => array_values(array_filter(array_map(fn ($x) => trim($x), explode(',', (string) $v))));
        $applies = array_filter([
            'structures' => array_map('strtoupper', $list($data['applies_structures'] ?? '')), 'categories' => $list($data['applies_categories'] ?? ''),
            'grade_min' => $data['grade_min'] ?? null, 'grade_max' => $data['grade_max'] ?? null,
        ]);
        $grades = [];
        foreach (preg_split('/\r?\n/', (string) ($data['grade_amounts_text'] ?? '')) as $line) {
            if (preg_match('/^\s*(\d{1,2})\s*[:=]\s*([\d,.]+)/', $line, $m)) {
                $grades[(string) (int) $m[1]] = (float) str_replace(',', '', $m[2]);
            }
        }
        unset($data['applies_structures'], $data['applies_categories'], $data['grade_min'], $data['grade_max'], $data['grade_amounts_text']);
        $data['code'] = strtoupper($data['code']);
        $data['applies_to'] = $applies ?: null;
        $data['grade_amounts'] = $grades ?: null;
        if ($record?->is_system) {
            // Statutory items keep their code, kind and method; their rates and names can change.
            unset($data['code'], $data['kind'], $data['method']);
        }

        return $data;
    }

    protected function recordData(Model $row, array $fields): array
    {
        $a = $row->applies_to ?? [];

        return parent::recordData($row, $fields) + [
            'applies_structures' => implode(', ', $a['structures'] ?? []), 'applies_categories' => implode(', ', $a['categories'] ?? []),
            'grade_min' => $a['grade_min'] ?? null, 'grade_max' => $a['grade_max'] ?? null,
            'grade_amounts_text' => collect($row->grade_amounts ?? [])->map(fn ($v, $g) => "{$g}: " . (0 + $v))->implode("\n"),
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        return $record->is_system ? 'This is a statutory item. Change its rate, or switch it off if it does not apply.' : null;
    }

    protected function viewData(Request $request): array
    {
        $can = $request->user()->can($this->gate);

        return ['abilities' => ['create' => $can, 'edit' => $can, 'delete' => $can]];
    }

    protected function columns(): array
    {
        return [
            ['Item', fn ($r) => new HtmlString('<strong>' . e($r->name) . '</strong> <span class="text-muted fs-12">' . e($r->code) . '</span>' . ($r->is_active ? '' : ' <span class="badge bg-secondary-subtle text-secondary">Off</span>'))],
            ['Kind', fn ($r) => PayElement::KINDS[$r->kind] ?? $r->kind],
            ['Amount', fn ($r) => $r->describe()],
            ['Flags', fn ($r) => collect([$r->kind === 'earning' && $r->taxable ? 'taxable' : null, $r->pensionable ? 'pensionable' : null, $r->tax_relief ? 'before tax' : null])->filter()->implode(' · ') ?: '—'],
            ['Applies to', fn ($r) => collect([implode(', ', $r->applies_to['structures'] ?? []), implode(', ', $r->applies_to['categories'] ?? []),
                isset($r->applies_to['grade_min']) || isset($r->applies_to['grade_max']) ? 'GL ' . ($r->applies_to['grade_min'] ?? 1) . '–' . ($r->applies_to['grade_max'] ?? '…') : null])->filter()->implode(' · ') ?: 'Everyone'],
        ];
    }
}
