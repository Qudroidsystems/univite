<?php

namespace App\Http\Controllers\Fees;

use App\Http\Controllers\Academics\CrudController;
use App\Models\Department;
use App\Models\DepartmentFeeRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/** Departments whose students may register courses before paying, and are reminded of what they owe. */
class DepartmentFeeRuleController extends CrudController
{
    protected string $permission = 'department fee rules';
    protected string $title = 'Department Fee Rules';
    protected string $singular = 'Department fee rule';
    protected string $icon = 'ri-scales-3-line';
    protected string $routeBase = 'fees.department-rules';
    protected string $intro = 'Let a department\'s students register courses before they pay. What they owe is then shown to them on their results, course registration, dashboard and in the app, so they are not caught unaware.';

    protected function model(): string
    {
        return DepartmentFeeRule::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('department.faculty')->reorder()->orderBy('department_id');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'department_id' => ['required', 'exists:departments,id', Rule::unique('department_fee_rules')->ignore($record?->id)],
            'register_without_paying' => ['nullable', 'boolean'],
            'show_owing' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function messages(): array
    {
        return ['department_id.unique' => 'This department already has a rule. Edit that one instead.'];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'department_id', 'lookup' => 'departments', 'label' => 'Department', 'type' => 'select', 'options' => Department::orderBy('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 12],
            ['name' => 'register_without_paying', 'label' => 'Students may register courses before paying', 'type' => 'switch', 'col' => 12, 'default' => true],
            ['name' => 'show_owing', 'label' => 'Show students what they owe (results, registration, dashboard, app)', 'type' => 'switch', 'col' => 12, 'default' => true],
            ['name' => 'note', 'label' => 'Message shown with the amount owed', 'type' => 'text', 'col' => 12, 'placeholder' => 'Please pay your fees before the examinations start.'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        $yes = '<span class="badge bg-success-subtle text-success">Yes</span>';
        $no = '<span class="badge bg-secondary-subtle text-secondary">No</span>';

        return [
            ['Department', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->department->name) . '</span><div class="text-muted fs-12">' . e($r->department->faculty?->name) . '</div>')],
            ['Register before paying', fn ($r) => new HtmlString($r->register_without_paying ? $yes : $no)],
            ['Shows what is owed', fn ($r) => new HtmlString($r->show_owing ? $yes : $no)],
            ['Message', fn ($r) => $r->note ?: '—'],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
