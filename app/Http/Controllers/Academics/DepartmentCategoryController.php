<?php

namespace App\Http\Controllers\Academics;

use App\Models\Department;
use App\Models\DepartmentCategory;
use App\Models\StudyCategory;
use App\Support\Levels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/** Which study categories each department runs, over which levels, with how many semesters a level has. */
class DepartmentCategoryController extends CrudController
{
    protected string $permission = 'department categories';
    protected string $title = 'Department Categories';
    protected string $singular = 'Department category';
    protected string $icon = 'ri-node-tree';
    protected string $routeBase = 'academics.department-categories';
    protected string $intro = 'The study categories each department runs (Degree, NCE, Part-time, Sandwich ...), the lowest and highest level, and how many semesters a level has (a Sandwich programme may have one semester a level over five levels). A programme in that department and category only runs the levels set here. "Allow combination" lets the department offer combined programmes such as Social Studies/English.';

    protected function model(): string
    {
        return DepartmentCategory::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('department.faculty', 'studyCategory')->reorder()->orderBy('department_id')->orderBy('study_category_id');
    }

    protected function filters(): array
    {
        return [
            ['department_id', 'Department', Department::orderBy('name')->pluck('name', 'id')->all()],
            ['study_category_id', 'Category', StudyCategory::orderBy('name')->pluck('name', 'id')->all()],
        ];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'department_id' => ['required', 'exists:departments,id'],
            'study_category_id' => ['required', 'exists:study_categories,id',
                Rule::unique('department_categories')->where('department_id', $request->input('department_id'))->ignore($record?->id)],
            'lowest_level' => ['required', 'integer', 'between:100,900', 'multiple_of:100'],
            'highest_level' => ['nullable', 'integer', 'between:100,900', 'multiple_of:100', 'gte:lowest_level'],
            'semesters_per_level' => ['required', 'integer', 'between:1,4'],
            'allow_combination' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function messages(): array
    {
        return ['study_category_id.unique' => 'This department already has this category. Edit that row instead.', 'highest_level.gte' => 'The highest level cannot be below the lowest.'];
    }

    protected function fields(): array
    {
        $levels = Levels::options() + [600 => Levels::label(600), 700 => Levels::label(700)];

        return [
            ['name' => 'department_id', 'lookup' => 'departments', 'label' => 'Department', 'type' => 'select', 'options' => Department::orderBy('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6],
            ['name' => 'study_category_id', 'lookup' => 'study-categories', 'label' => 'Study category', 'type' => 'select', 'options' => StudyCategory::orderBy('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6],
            ['name' => 'lowest_level', 'label' => 'Lowest level', 'type' => 'select', 'options' => $levels, 'required' => true, 'col' => 4, 'default' => 100],
            ['name' => 'highest_level', 'label' => 'Highest level', 'type' => 'select', 'options' => $levels, 'blank' => 'Programme duration decides', 'col' => 4],
            ['name' => 'semesters_per_level', 'label' => 'Semesters per level', 'type' => 'number', 'required' => true, 'col' => 4, 'default' => 2],
            ['name' => 'allow_combination', 'label' => 'Allow combined programmes (e.g. Social Studies/English)', 'type' => 'switch', 'col' => 12],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Department', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->department->name) . '</span><div class="text-muted fs-12">' . e($r->department->faculty?->name) . '</div>')],
            ['Category', fn ($r) => $r->studyCategory?->name],
            ['Levels', fn ($r) => Levels::label($r->lowest_level, $r->studyCategory) . ' to ' . ($r->highest_level ? Levels::label($r->highest_level, $r->studyCategory) : 'end of programme')],
            ['Semesters a level', fn ($r) => $r->semesters_per_level],
            ['Combined programmes', fn ($r) => new HtmlString($r->allow_combination ? '<i class="ri-check-line text-success"></i>' : '<span class="text-muted">—</span>')],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
