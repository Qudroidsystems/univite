<?php

namespace App\Http\Controllers\Academics;

use App\Models\Faculty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class FacultyController extends CrudController
{
    protected string $permission = 'faculties';
    protected string $title = 'Faculties';
    protected string $singular = 'Faculty';
    protected string $icon = 'ri-building-2-line';
    protected string $routeBase = 'academics.faculties';
    protected string $intro = 'Faculties (or Schools/Colleges) are the top level of the academic structure. Departments sit under them.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return Faculty::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('dean')->withCount('departments')->reorder()->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name'        => ['required', 'string', 'max:255', Rule::unique('faculties', 'name')->ignore($record?->id)],
            'code'        => ['required', 'string', 'max:20', Rule::unique('faculties', 'code')->ignore($record?->id)],
            'dean_id'     => ['nullable', 'exists:users,id'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active'   => ['nullable', 'boolean'],
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
            ['name' => 'name', 'label' => 'Faculty name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Faculty of Science'],
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'SCI'],
            ['name' => 'dean_id', 'label' => 'Dean', 'type' => 'select', 'options' => User::orderBy('name')->pluck('name', 'id')->all(), 'col' => 12, 'blank' => 'Not assigned'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'col' => 12],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Code', fn ($r) => new HtmlString('<span class="badge bg-light text-body border">' . e($r->code) . '</span>')],
            ['Faculty', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>')],
            ['Dean', fn ($r) => $r->dean?->name ?? '—'],
            ['Departments', fn ($r) => $r->departments_count],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
