<?php

namespace App\Http\Controllers\Academics;

use App\Models\Course;
use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class CourseController extends CrudController
{
    protected string $permission = 'course';
    protected string $title = 'Courses';
    protected string $singular = 'Course';
    protected string $icon = 'ri-book-open-line';
    protected string $routeBase = 'academics.courses';
    protected string $intro = 'The course catalogue. Each course has a code, a title and its credit units. Use Curriculum to decide which programme takes which course at which level and semester.';
    protected array $searchable = ['code', 'title'];

    public const LEVELS = [100 => '100 Level', 200 => '200 Level', 300 => '300 Level', 400 => '400 Level', 500 => '500 Level', 600 => '600 Level', 700 => '700 Level', 800 => '800 Level'];

    protected function model(): string
    {
        return Course::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('department')->reorder()->orderBy('code');
    }

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
            ['level', 'Level', self::LEVELS],
        ];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'department_id' => ['required', 'exists:departments,id'],
            'code'          => ['required', 'string', 'max:20', Rule::unique('courses', 'code')->ignore($record?->id)],
            'title'         => ['required', 'string', 'max:255'],
            'credit_units'  => ['required', 'integer', 'min:0', 'max:12'],
            'level'         => ['nullable', Rule::in(array_keys(self::LEVELS))],
            'description'   => ['nullable', 'string', 'max:2000'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        // "csc  101" -> "CSC 101"
        $data['code'] = strtoupper(preg_replace('/\s+/', ' ', trim($data['code'])));

        return $data;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'department_id', 'label' => 'Owning department', 'type' => 'select', 'options' => $this->departmentOptions(), 'required' => true, 'col' => 12],
            ['name' => 'code', 'label' => 'Course code', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'CSC 101'],
            ['name' => 'title', 'label' => 'Course title', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Introduction to Computer Science'],
            ['name' => 'credit_units', 'label' => 'Credit units', 'type' => 'number', 'required' => true, 'col' => 6, 'default' => 2],
            ['name' => 'level', 'label' => 'Level', 'type' => 'select', 'options' => self::LEVELS, 'col' => 6, 'blank' => 'Any level'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'col' => 12],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Code', fn ($r) => new HtmlString('<span class="badge bg-primary-subtle text-primary">' . e($r->code) . '</span>')],
            ['Title', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->title) . '</span>')],
            ['Department', fn ($r) => $r->department->name],
            ['Level', fn ($r) => $r->level ? $r->level . 'L' : '—'],
            ['Units', fn ($r) => $r->credit_units],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
