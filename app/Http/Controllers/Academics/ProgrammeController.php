<?php

namespace App\Http\Controllers\Academics;

use App\Models\Department;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class ProgrammeController extends CrudController
{
    protected string $permission = 'programmes';
    protected string $title = 'Programmes';
    protected string $singular = 'Programme';
    protected string $icon = 'ri-graduation-cap-line';
    protected string $routeBase = 'academics.programmes';
    protected string $intro = 'A programme is a degree or diploma a department awards, e.g. B.Sc. Computer Science. Its duration decides the levels it runs (100 to 400, 500 ...). The credit load limits are used later for course registration.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return Programme::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('department.faculty', 'studyCategory')->reorder()->orderBy('name');
    }

    /** Grouped by faculty so a long department list stays readable. */
    protected function departmentOptions(): array
    {
        return Department::with('faculty')->orderBy('name')->get()
            ->groupBy(fn ($d) => $d->faculty->name)
            ->map(fn ($group) => $group->pluck('name', 'id')->all())
            ->sortKeys()
            ->all();
    }

    protected function filters(): array
    {
        return [
            ['department_id', 'Department', Department::orderBy('name')->pluck('name', 'id')->all()],
            ['study_category_id', 'Category', \App\Models\StudyCategory::orderBy('name')->pluck('name', 'id')->all()],
        ];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'department_id'   => ['required', 'exists:departments,id'],
            'name'            => ['required', 'string', 'max:255', Rule::unique('programmes', 'name')->where('department_id', $request->input('department_id'))->ignore($record?->id)],
            'code'            => ['required', 'string', 'max:20', Rule::unique('programmes', 'code')->ignore($record?->id)],
            'study_category_id' => ['required', Rule::exists('study_categories', 'id')],
            'degree_type'     => ['required', 'string', 'max:30'],
            'duration_years'  => ['required', 'integer', 'min:1', 'max:8'],
            'min_credit_load' => ['required', 'integer', 'min:0', 'max:60'],
            'max_credit_load' => ['required', 'integer', 'gte:min_credit_load', 'max:60'],
            'is_active'       => ['nullable', 'boolean'],
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
            ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'options' => $this->departmentOptions(), 'required' => true, 'col' => 12],
            ['name' => 'name', 'label' => 'Programme name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'B.Sc. Computer Science'],
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'BSC-CSC'],
            ['name' => 'study_category_id', 'label' => 'Study category', 'type' => 'select', 'options' => \App\Models\StudyCategory::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6, 'help' => 'Degree, NCE, Part-time ... set up under Study Categories.'],
            ['name' => 'degree_type', 'label' => 'Qualification awarded', 'type' => 'text', 'required' => true, 'col' => 6, 'placeholder' => 'B.Sc. (Ed.)'],
            ['name' => 'duration_years', 'label' => 'Duration (years)', 'type' => 'number', 'required' => true, 'col' => 6, 'default' => 4],
            ['name' => 'min_credit_load', 'label' => 'Min credit units / semester', 'type' => 'number', 'required' => true, 'col' => 6, 'default' => 15],
            ['name' => 'max_credit_load', 'label' => 'Max credit units / semester', 'type' => 'number', 'required' => true, 'col' => 6, 'default' => 24],
            ['name' => 'is_active', 'label' => 'Active (accepting students)', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Code', fn ($r) => new HtmlString('<span class="badge bg-light text-body border">' . e($r->code) . '</span>')],
            ['Programme', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span><div class="text-muted fs-12">' . e($r->department->name) . ' · ' . e($r->department->faculty->name) . '</div>')],
            ['Category', fn ($r) => new HtmlString(e($r->studyCategory?->name ?? '—') . '<div class="text-muted fs-12">' . e($r->degree_type) . '</div>')],
            ['Duration', fn ($r) => $r->duration_years . ' yr' . ($r->duration_years > 1 ? 's' : '')],
            ['Credit load', fn ($r) => $r->min_credit_load . ' – ' . $r->max_credit_load . ' units'],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
