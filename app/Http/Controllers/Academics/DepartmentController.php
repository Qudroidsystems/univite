<?php

namespace App\Http\Controllers\Academics;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class DepartmentController extends CrudController
{
    protected string $permission = 'department';
    protected string $title = 'Departments';
    protected string $singular = 'Department';
    protected string $icon = 'ri-organization-chart';
    protected string $routeBase = 'academics.departments';
    protected string $intro = 'Every department belongs to a faculty and offers programmes and courses.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return Department::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with(['faculty', 'hod'])->withCount(['programmes', 'courses'])->reorder()->orderBy('name');
    }

    protected function facultyOptions(): array
    {
        return Faculty::orderBy('name')->pluck('name', 'id')->all();
    }

    protected function filters(): array
    {
        return [['faculty_id', 'Faculty', $this->facultyOptions()]];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'faculty_id'  => ['required', 'exists:faculties,id'],
            'name'        => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where('faculty_id', $request->input('faculty_id'))->ignore($record?->id)],
            'code'        => ['required', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($record?->id)],
            'hod_id'      => ['nullable', 'exists:users,id'],
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
            ['name' => 'faculty_id', 'label' => 'Faculty', 'type' => 'select', 'options' => $this->facultyOptions(), 'required' => true, 'col' => 12],
            ['name' => 'name', 'label' => 'Department name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Computer Science'],
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'CSC'],
            ['name' => 'hod_id', 'label' => 'Head of department', 'type' => 'select', 'options' => User::orderBy('name')->pluck('name', 'id')->all(), 'col' => 12, 'blank' => 'Not assigned'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'col' => 12],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Code', fn ($r) => new HtmlString('<span class="badge bg-light text-body border">' . e($r->code) . '</span>')],
            ['Department', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>')],
            ['Faculty', fn ($r) => $r->faculty->name],
            ['HOD', fn ($r) => $r->hod?->name ?? '—'],
            ['Programmes', fn ($r) => $r->programmes_count],
            ['Courses', fn ($r) => $r->courses_count],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
