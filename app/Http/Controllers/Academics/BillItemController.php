<?php

namespace App\Http\Controllers\Academics;

use App\Models\BillItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class BillItemController extends CrudController
{
    protected string $permission = 'bill-item';
    protected string $title = 'Bill Items';
    protected string $singular = 'Bill item';
    protected string $icon = 'ri-price-tag-3-line';
    protected string $routeBase = 'bills.items';
    protected string $intro = 'The things a student can be billed for: tuition, acceptance fee, hostel, library, ICT, etc. Set the amounts under School Bills.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return BillItem::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name'          => ['required', 'string', 'max:255', Rule::unique('bill_items', 'name')->ignore($record?->id)],
            'code'          => ['required', 'string', 'max:20', Rule::unique('bill_items', 'code')->ignore($record?->id)],
            'description'   => ['nullable', 'string', 'max:1000'],
            'is_compulsory' => ['nullable', 'boolean'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $data['code'] = strtoupper($data['code']);

        return $data;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Tuition'],
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'TUI'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'col' => 12],
            ['name' => 'is_compulsory', 'label' => 'Compulsory for every student', 'type' => 'switch', 'col' => 12, 'default' => true],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Code', fn ($r) => new HtmlString('<span class="badge bg-light text-body border">' . e($r->code) . '</span>')],
            ['Item', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>')],
            ['Type', fn ($r) => $r->is_compulsory ? 'Compulsory' : 'Optional'],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
