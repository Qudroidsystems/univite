<?php

namespace App\Http\Controllers\Academics;

use App\Models\Lookup;
use App\Support\ReferenceData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class LookupController extends CrudController
{
    protected string $permission = 'reference lists';
    protected string $title = 'Reference Lists';
    protected string $singular = 'List entry';
    protected string $icon = 'ri-list-check-2';
    protected string $routeBase = 'academics.lists';
    protected string $intro = 'The choices offered in dropdowns across the portal: titles, gender, religion, blood group, relationships, states and so on. Pick a list to see its entries, then add, rename, reorder or retire them. Retiring an entry hides it from new forms but keeps it on old records.';
    protected array $searchable = ['label', 'code'];
    protected int $perPage = 60;

    protected function model(): string
    {
        return Lookup::class;
    }

    protected function listOptions(): array
    {
        return collect(ReferenceData::lists())->map(fn ($l) => $l[0])->all();
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderBy('list')->orderBy('sort_order')->orderBy('label');
    }

    protected function filters(): array
    {
        return [['list', 'List', $this->listOptions()]];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $list = $record?->list ?? $request->input('list');

        return [
            'list'       => $record ? ['prohibited'] : ['required', Rule::in(array_keys($this->listOptions()))],
            'code'       => $record ? ['prohibited'] : ['required', 'string', 'max:100', Rule::unique('lookups', 'code')->where('list', $list)],
            'label'      => ['required', 'string', 'max:150'],
            'sort_order' => ['required', 'integer', 'between:0,9999'],
            'is_active'  => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'list', 'label' => 'List', 'type' => 'select', 'options' => $this->listOptions(), 'required' => true, 'col' => 6, 'only' => 'create'],
            ['name' => 'code', 'label' => 'Code (cannot change later)', 'type' => 'text', 'required' => true, 'col' => 6, 'only' => 'create', 'help' => 'Short and stable, e.g. widowed.'],
            ['name' => 'label', 'label' => 'Shown as', 'type' => 'text', 'required' => true, 'col' => 8],
            ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'required' => true, 'col' => 4, 'default' => 50],
            ['name' => 'is_active', 'label' => 'Active (offered in forms)', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['List', fn ($r) => $this->listOptions()[$r->list] ?? $r->list],
            ['Shown as', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->label) . '</span>')],
            ['Code', fn ($r) => new HtmlString('<span class="badge bg-light text-body border">' . e($r->code) . '</span>')],
            ['Order', fn ($r) => $r->sort_order],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Retired</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        return $record->is_system ? 'The portal depends on this entry. Rename or retire it instead of deleting it.' : null;
    }
}
