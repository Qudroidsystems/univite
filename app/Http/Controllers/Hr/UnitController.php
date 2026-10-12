<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Academics\CrudController;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/** Non-academic offices and their heads. The head gets the unit head role for the unit (see Scoped Roles). */
class UnitController extends CrudController
{
    protected string $permission = 'units';
    protected string $title = 'Units';
    protected string $singular = 'Unit';
    protected string $icon = 'ri-building-4-line';
    protected string $routeBase = 'units';
    protected string $intro = 'Offices outside the faculties, such as the Bursary, Registry, Works or Library. Staff belong to a department or a unit; the head of a unit sees the records and leave of its staff.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return Unit::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('head')->withCount('staff')->reorder()->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('units', 'name')->ignore($record?->id)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('units', 'code')->ignore($record?->id)],
            'head_id' => ['nullable', 'exists:users,id'], 'description' => ['nullable', 'string', 'max:1000'], 'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'col' => 8, 'placeholder' => 'Bursary'],
            ['name' => 'code', 'label' => 'Code', 'col' => 4, 'placeholder' => 'BUR'],
            ['name' => 'head_id', 'label' => 'Head', 'type' => 'select', 'options' => User::whereDoesntHave('student')->orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'No head yet', 'col' => 8],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'default' => true, 'col' => 4],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $data['code'] = isset($data['code']) ? strtoupper($data['code']) : null;

        return $data;
    }

    protected function blockDelete(Model $record): ?string
    {
        return $record->staff()->exists() ? 'Staff still belong to this unit. Move them first, or make the unit inactive.' : null;
    }

    protected function columns(): array
    {
        return [
            ['Unit', fn ($r) => new HtmlString('<strong>' . e($r->name) . '</strong>' . ($r->code ? ' <span class="text-muted">' . e($r->code) . '</span>' : '') . ($r->is_active ? '' : ' <span class="badge bg-secondary-subtle text-secondary">Inactive</span>'))],
            ['Head', fn ($r) => $r->head?->name ?? '—'],
            ['Staff', fn ($r) => $r->staff_count],
        ];
    }
}
