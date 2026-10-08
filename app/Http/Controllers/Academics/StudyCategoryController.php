<?php

namespace App\Http\Controllers\Academics;

use App\Models\StudyCategory;
use App\Support\Levels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class StudyCategoryController extends CrudController
{
    protected string $permission = 'study categories';
    protected string $title = 'Study Categories';
    protected string $singular = 'Study category';
    protected string $icon = 'ri-stack-line';
    protected string $routeBase = 'academics.study-categories';
    protected string $intro = 'The kinds of award your university runs, such as Degree, NCE, Part-time, Sandwich, Pre-degree or Diploma. Every programme belongs to one. The level format controls how levels read for the category: "{LEVEL} Level" gives 100 Level; "Level {N}" gives Level 1; "Part {N}" gives Part 1.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return StudyCategory::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->withCount('programmes')->reorder()->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name'           => ['required', 'string', 'max:100', Rule::unique('study_categories', 'name')->ignore($record?->id)],
            'code'           => ['required', 'string', 'max:20', Rule::unique('study_categories', 'code')->ignore($record?->id)],
            'duration_years' => ['required', 'integer', 'min:1', 'max:8'],
            'level_format'   => ['nullable', 'string', 'max:50', 'regex:/\{(LEVEL|N)\}/'],
            'is_active'      => ['nullable', 'boolean'],
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
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'NCE Part-time'],
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'NCEPT'],
            ['name' => 'duration_years', 'label' => 'Usual duration (years)', 'type' => 'number', 'required' => true, 'col' => 6, 'default' => 4, 'help' => 'Programmes in this category start with this duration.'],
            ['name' => 'level_format', 'label' => 'Level format', 'type' => 'text', 'col' => 6, 'placeholder' => Levels::DEFAULT_FORMAT, 'help' => 'Must contain {LEVEL} or {N}. Blank = ' . Levels::DEFAULT_FORMAT],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Code', fn ($r) => new HtmlString('<span class="badge bg-light text-body border">' . e($r->code) . '</span>')],
            ['Category', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>')],
            ['Duration', fn ($r) => $r->duration_years . ' yr' . ($r->duration_years > 1 ? 's' : '')],
            ['Levels read as', fn ($r) => Levels::label(100, $r) . ', ' . Levels::label(200, $r) . ' ...'],
            ['Programmes', fn ($r) => $r->programmes_count],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        return $record->programmes()->exists() ? 'Programmes still belong to this category. Move them first, or mark the category inactive.' : null;
    }
}
