<?php

namespace App\Http\Controllers\Academics;

use App\Models\CourseOption;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/** Specialisations inside a programme, e.g. Acting and Directing in Theatre Arts. */
class CourseOptionController extends CrudController
{
    protected string $permission = 'course options';
    protected string $title = 'Course Options';
    protected string $singular = 'Course option';
    protected string $icon = 'ri-git-merge-line';
    protected string $routeBase = 'academics.course-options';
    protected string $intro = 'Specialisations inside a programme, such as Acting or Directing in Theatre Arts. A student can be placed in an option, and fees and curriculum courses can be limited to one option.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return CourseOption::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('programme.department')->reorder()->orderBy('programme_id')->orderBy('name');
    }

    protected function filters(): array
    {
        return [['programme_id', 'Programme', Programme::orderBy('name')->pluck('name', 'id')->all()]];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'programme_id' => ['required', 'exists:programmes,id'],
            'name' => ['required', 'string', 'max:150', Rule::unique('course_options')->where('programme_id', $request->input('programme_id'))->ignore($record?->id)],
            'code' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $data['code'] = isset($data['code']) ? strtoupper($data['code']) : null;

        return $data;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'programme_id', 'lookup' => 'programmes', 'label' => 'Programme', 'type' => 'select', 'options' => Programme::orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->name} ({$p->code})"])->all(), 'required' => true, 'col' => 12],
            ['name' => 'name', 'label' => 'Option', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Acting'],
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'col' => 4],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Option', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>' . ($r->code ? ' <span class="badge bg-light text-body border">' . e($r->code) . '</span>' : ''))],
            ['Programme', fn ($r) => new HtmlString(e($r->programme->name) . '<div class="text-muted fs-12">' . e($r->programme->department?->name) . '</div>')],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('students', 'course_option_id') && \App\Models\Student::where('course_option_id', $record->id)->exists()
            ? 'Students are in this option. Move them first, or mark it inactive.' : null;
    }
}
