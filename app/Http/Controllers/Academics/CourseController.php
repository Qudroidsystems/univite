<?php

namespace App\Http\Controllers\Academics;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\SemesterType;
use App\Models\StudyCategory;
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
    protected string $intro = 'The course catalogue. Choose the faculty and department that own the course, the study category, level, session and semester, then its code, title and credit units. Use Curriculum to decide which programmes take the course.';
    protected array $searchable = ['code', 'title'];

    protected function model(): string
    {
        return Course::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('department.faculty', 'studyCategory', 'semesterType', 'session', 'prerequisites')->reorder()->orderBy('code');
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
            ['study_category_id', 'Study category', StudyCategory::orderBy('name')->pluck('name', 'id')->all()],
            ['level', 'Level', \App\Support\Levels::options()],
            ['semester_type_id', 'Semester', SemesterType::orderBy('sort_order')->pluck('name', 'id')->all()],
        ];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'faculty_id'    => ['nullable', 'exists:faculties,id'],
            'department_id' => ['required', $request->filled('faculty_id')
                ? Rule::exists('departments', 'id')->where('faculty_id', $request->input('faculty_id'))
                : 'exists:departments,id'],
            'study_category_id' => ['nullable', 'exists:study_categories,id'],
            'semester_type_id' => ['nullable', 'exists:semester_types,id'],
            'academic_session_id' => ['nullable', 'exists:academic_sessions,id'],
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
        return array_merge(parent::recordData($row, $fields), [
            'faculty_id' => (string) ($row->department?->faculty_id ?? ''),
            'prerequisites' => $row->prerequisites->pluck('code')->implode(', '),
        ]);
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        // "csc  101" -> "CSC 101"
        $data['code'] = strtoupper(preg_replace('/\s+/', ' ', trim($data['code'])));
        unset($data['prerequisites'], $data['faculty_id']);

        return $data;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'faculty_id', 'lookup' => 'faculties', 'label' => 'Faculty / school', 'type' => 'select', 'options' => Faculty::orderBy('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6],
            ['name' => 'department_id', 'lookup' => 'departments', 'lookup_parent' => 'faculty_id', 'label' => 'Department', 'type' => 'select', 'options' => $this->departmentOptions(), 'required' => true, 'col' => 6],
            ['name' => 'study_category_id', 'lookup' => 'study-categories', 'label' => 'Student category', 'type' => 'select', 'options' => StudyCategory::orderBy('name')->pluck('name', 'id')->all(), 'col' => 6, 'blank' => 'Every category'],
            ['name' => 'level', 'label' => 'Level', 'type' => 'select', 'options' => \App\Support\Levels::options(), 'col' => 6, 'blank' => 'Any level'],
            ['name' => 'academic_session_id', 'lookup' => 'sessions', 'label' => 'Session (introduced)', 'type' => 'select', 'options' => AcademicSession::orderByDesc('name')->pluck('name', 'id')->all(), 'col' => 6, 'blank' => 'Not set'],
            ['name' => 'semester_type_id', 'lookup' => 'semester-types', 'label' => 'Semester', 'type' => 'select', 'options' => SemesterType::orderBy('sort_order')->pluck('name', 'id')->all(), 'col' => 6, 'blank' => 'Either semester'],
            ['name' => 'code', 'label' => 'Course code', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'CSC 101'],
            ['name' => 'title', 'label' => 'Course title', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Introduction to Computer Science'],
            ['name' => 'credit_units', 'label' => 'Course units', 'type' => 'number', 'required' => true, 'col' => 6, 'default' => 2],
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
            ['Department', fn ($r) => new HtmlString(e($r->department->name) . '<div class="text-muted fs-12">' . e($r->department->faculty?->name) . '</div>')],
            ['Category', fn ($r) => $r->studyCategory?->name ?? 'All'],
            ['Level', fn ($r) => $r->level ? $r->level . 'L' : '—'],
            ['Semester', fn ($r) => trim(($r->semesterType?->name ?? '—') . ($r->session ? ' · from ' . $r->session->name : ''))],
            ['Units', fn ($r) => $r->credit_units],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
