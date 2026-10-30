<?php

namespace App\Http\Controllers\Academics;

use App\Models\Department;
use App\Models\StudyCategory;
use App\Models\SubDepartment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/** Units inside a department, e.g. Christian Religious Studies and Islamic Studies inside Religious Studies. */
class SubDepartmentController extends CrudController
{
    protected string $permission = 'sub departments';
    protected string $title = 'Sub-departments';
    protected string $singular = 'Sub-department';
    protected string $icon = 'ri-git-branch-line';
    protected string $routeBase = 'academics.sub-departments';
    protected string $intro = 'Units inside a department, such as Christian Religious Studies inside Religious Studies. Programmes and students can belong to a sub-department, and lists and reports can be filtered by it.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return SubDepartment::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('department.faculty', 'studyCategory')->withCount('programmes')->reorder()->orderBy('name');
    }

    protected function filters(): array
    {
        return [['department_id', 'Department', Department::orderBy('name')->pluck('name', 'id')->all()]];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'department_id' => ['required', 'exists:departments,id'],
            'study_category_id' => ['nullable', 'exists:study_categories,id'],
            'name' => ['required', 'string', 'max:150', Rule::unique('sub_departments')->where('department_id', $request->input('department_id'))->ignore($record?->id)],
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
            ['name' => 'department_id', 'lookup' => 'departments', 'label' => 'Department', 'type' => 'select', 'options' => Department::orderBy('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6],
            ['name' => 'study_category_id', 'lookup' => 'study-categories', 'label' => 'Study category', 'type' => 'select', 'options' => StudyCategory::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'All categories', 'col' => 6],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Christian Religious Studies'],
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'col' => 4, 'placeholder' => 'CRS'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Sub-department', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>' . ($r->code ? ' <span class="badge bg-light text-body border">' . e($r->code) . '</span>' : ''))],
            ['Department', fn ($r) => new HtmlString(e($r->department->name) . '<div class="text-muted fs-12">' . e($r->department->faculty?->name) . '</div>')],
            ['Category', fn ($r) => $r->studyCategory?->name ?? 'All'],
            ['Programmes', fn ($r) => $r->programmes_count],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        return $record->programmes()->exists() || \Illuminate\Support\Facades\Schema::hasColumn('students', 'sub_department_id') && \App\Models\Student::where('sub_department_id', $record->id)->exists()
            ? 'Programmes or students still belong to this sub-department. Move them first, or mark it inactive.' : null;
    }
}
