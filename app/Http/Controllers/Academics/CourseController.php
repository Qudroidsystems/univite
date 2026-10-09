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
    protected string $permission = 'courses';
    protected string $title = 'Courses';
    protected string $singular = 'Course';
    protected string $icon = 'ri-book-open-line';
    protected string $routeBase = 'academics.courses';
    protected string $intro = 'The course catalogue. Each course has a code, a title and its credit units. Use Curriculum to decide which programme takes which course at which level and semester.';
    protected array $searchable = ['code', 'title'];

    protected function model(): string
    {
        return Course::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('department', 'prerequisites')->reorder()->orderBy('code');
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
            ['level', 'Level', \App\Support\Levels::options()],
        ];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'department_id' => ['required', 'exists:departments,id'],
            'code'          => ['required', 'string', 'max:20', Rule::unique('courses', 'code')->ignore($record?->id)],
            'title'         => ['required', 'string', 'max:255'],
            'credit_units'  => ['required', 'integer', 'min:0', 'max:12'],
            'level'         => ['nullable', Rule::in(array_keys(\App\Support\Levels::options()))],
            'description'   => ['nullable', 'string', 'max:2000'],
            'is_active'     => ['nullable', 'boolean'],
            'prerequisites' => ['nullable', 'string', 'max:500', function ($attr, $value, $fail) use ($record) {
                $codes = $this->codes($value);
                $known = Course::whereIn('code', $codes)->pluck('code')->all();
                if ($unknown = array_diff($codes, $known)) {
                    $fail('These course codes do not exist: ' . implode(', ', $unknown) . '.');
                }
                if ($record && in_array($record->code, $codes, true)) {
                    $fail('A course cannot be its own prerequisite.');
                }
            }],
        ];
    }

    /** "csc 101, CSC 102" -> ['CSC 101', 'CSC 102'] */
    private function codes(?string $text): array
    {
        return collect(preg_split('/[,;\n]+/', (string) $text))->map(fn ($c) => strtoupper(preg_replace('/\s+/', ' ', trim($c))))->filter()->unique()->values()->all();
    }

    protected function saved(Model $record, Request $request, bool $created): void
    {
        $record->prerequisites()->sync(Course::whereIn('code', $this->codes($request->input('prerequisites')))->pluck('id')->all());
    }

    protected function recordData(Model $row, array $fields): array
    {
        return array_merge(parent::recordData($row, $fields), ['prerequisites' => $row->prerequisites->pluck('code')->implode(', ')]);
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        // "csc  101" -> "CSC 101"
        $data['code'] = strtoupper(preg_replace('/\s+/', ' ', trim($data['code'])));
        unset($data['prerequisites']);

        return $data;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'department_id', 'label' => 'Owning department', 'type' => 'select', 'options' => $this->departmentOptions(), 'required' => true, 'col' => 12],
            ['name' => 'code', 'label' => 'Course code', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'CSC 101'],
            ['name' => 'title', 'label' => 'Course title', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Introduction to Computer Science'],
            ['name' => 'credit_units', 'label' => 'Credit units', 'type' => 'number', 'required' => true, 'col' => 6, 'default' => 2],
            ['name' => 'level', 'label' => 'Level', 'type' => 'select', 'options' => \App\Support\Levels::options(), 'col' => 6, 'blank' => 'Any level'],
            ['name' => 'prerequisites', 'label' => 'Prerequisites (course codes, comma separated)', 'type' => 'text', 'col' => 12, 'placeholder' => 'CSC 101, MTH 101', 'help' => 'A student must have passed these before registering for this course.'],
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
