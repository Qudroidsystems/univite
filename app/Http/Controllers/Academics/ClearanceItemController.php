<?php

namespace App\Http\Controllers\Academics;

use App\Models\ClearanceItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** The clearance checklist this university requires before a matric number is issued. */
class ClearanceItemController extends CrudController
{
    protected string $permission = 'clearance items';
    protected string $title = 'Clearance Items';
    protected string $singular = 'Clearance item';
    protected string $icon = 'ri-checkbox-multiple-line';
    protected string $routeBase = 'academics.clearance-items';
    protected string $intro = 'What a new student must clear before a matric number is issued. Automatic items tick themselves from what the portal already knows (offer accepted, fees paid ...). Manual items are signed off by an office, such as the health centre or the department. Switch an item off to stop it blocking students.';

    protected function model(): string
    {
        return ClearanceItem::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderBy('sort_order')->orderBy('id');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name'        => ['required', 'string', 'max:120'],
            'code'        => $record ? ['prohibited'] : ['required', 'alpha_dash', 'max:40', 'unique:clearance_items,code'],
            'description' => ['nullable', 'string', 'max:500'],
            'mode'        => ['required', Rule::in(['auto', 'manual'])],
            'auto_rule'   => [Rule::requiredIf($request->input('mode') === 'auto'), 'nullable', Rule::in(array_keys(ClearanceItem::RULES))],
            'office_role' => ['nullable', 'string', Rule::exists('roles', 'name')],
            'is_required' => ['nullable', 'boolean'],
            'is_active'   => ['nullable', 'boolean'],
            'sort_order'  => ['nullable', 'integer', 'between:0,65000'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        if (isset($data['code'])) {
            $data['code'] = strtolower($data['code']);
        }
        if (($data['mode'] ?? null) === 'auto') {
            $data['office_role'] = null;
        } else {
            $data['auto_rule'] = null;
        }
        $data['sort_order'] ??= 0;

        return $data;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'e.g. Library clearance'],
            ['name' => 'code', 'label' => 'Short code', 'type' => 'text', 'required' => true, 'col' => 4, 'only' => 'create', 'help' => 'Letters, numbers and dashes. Cannot be changed later.'],
            ['name' => 'description', 'label' => 'What it means', 'type' => 'textarea', 'col' => 12],
            ['name' => 'mode', 'label' => 'How it is cleared', 'type' => 'select', 'options' => ['auto' => 'Automatically by the portal', 'manual' => 'Signed off by an office'], 'required' => true, 'col' => 4, 'default' => 'manual'],
            ['name' => 'auto_rule', 'label' => 'Automatic check', 'type' => 'select', 'options' => ClearanceItem::RULES, 'col' => 8, 'blank' => 'Not applicable (manual item)', 'help' => 'Only for automatic items.'],
            ['name' => 'office_role', 'label' => 'Office that signs it', 'type' => 'select', 'options' => Role::orderBy('name')->pluck('name', 'name')->all(), 'col' => 8, 'blank' => 'Any clearance officer', 'help' => 'Only for manual items. Staff with this role can sign it.'],
            ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'col' => 4, 'default' => 100],
            ['name' => 'is_required', 'label' => 'Required for clearance', 'type' => 'switch', 'col' => 6, 'default' => true],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 6, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Order', fn ($r) => $r->sort_order],
            ['Item', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>' . ($r->description ? '<div class="text-muted fs-12">' . e($r->description) . '</div>' : ''))],
            ['Cleared by', fn ($r) => $r->isAuto() ? 'Automatic: ' . (ClearanceItem::RULES[$r->auto_rule] ?? $r->auto_rule) : 'Office: ' . ($r->office_role ?: 'any clearance officer')],
            ['Required', fn ($r) => $r->is_required ? 'Yes' : 'No'],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Off</span>')],
        ];
    }
}
