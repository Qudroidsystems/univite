<?php

namespace App\Http\Controllers\Academics;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\SemesterType;
use App\Models\StudyCategory;
use App\Support\CourseSharing;
use App\Support\CourseType;
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
    protected string $intro = 'The course catalogue. Each course belongs to the department that runs it. That department sets its type for its own programmes (compulsory, compulsory elective or elective) and decides whether other departments may take it, and as what. Prerequisites are picked from the list of courses. Use Curriculum to place courses in programmes.';
    protected array $searchable = ['code', 'title', 'description'];

    protected function model(): string
    {
        return Course::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('department.faculty', 'studyCategory', 'semesterType', 'session', 'prerequisites')->withCount('programmeCourses')->reorder()->orderBy('code');
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
        $faculty = request('faculty');
        $departments = Department::query()->when($faculty, fn ($q) => $q->where('faculty_id', $faculty))->orderBy('name')->pluck('name', 'id')->all();

        return [
            ['faculty', 'Faculty', Faculty::orderBy('name')->pluck('name', 'id')->all()],
            ['department_id', 'Department', $departments],
            ['study_category_id', 'Study category', StudyCategory::orderBy('name')->pluck('name', 'id')->all()],
            ['level', 'Level', \App\Support\Levels::options()],
            ['semester_type_id', 'Semester', SemesterType::orderBy('sort_order')->pluck('name', 'id')->all()],
            ['academic_session_id', 'Session', AcademicSession::orderByDesc('name')->pluck('name', 'id')->all(), 'All sessions'],
            ['course_type', 'Type', CourseType::options(), 'All types'],
            ['credit_units', 'Unit', Course::query()->distinct()->orderBy('credit_units')->pluck('credit_units')->mapWithKeys(fn ($u) => [$u => $u . ' unit' . ($u == 1 ? '' : 's')])->all(), 'Any units', true],
            ['shared_as', 'Sharing', ['own' => 'Own department only'] + array_filter(CourseSharing::options(), fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY), 'Any sharing', true],
            ['prerequisite', 'Prerequisite', ['with' => 'Has prerequisites', 'without' => 'No prerequisites'], 'With or without prerequisites', true],
            ['curriculum', 'Curriculum', ['on' => 'On a curriculum', 'off' => 'On no curriculum'], 'On a curriculum or not', true],
            ['is_active', 'Status', ['1' => 'Active', '0' => 'Inactive'], 'Active and inactive', true],
        ];
    }

    protected function applyFilter(Builder $query, string $name, mixed $value): bool
    {
        match ($name) {
            'faculty' => $query->whereHas('department', fn ($d) => $d->where('faculty_id', $value)),
            'shared_as' => $value === 'own' ? $query->whereNull('shared_as') : $query->where('shared_as', $value),
            'prerequisite' => $value === 'with' ? $query->has('prerequisites') : $query->doesntHave('prerequisites'),
            'curriculum' => $value === 'on' ? $query->has('programmeCourses') : $query->doesntHave('programmeCourses'),
            default => null,
        };

        return in_array($name, ['faculty', 'shared_as', 'prerequisite', 'curriculum'], true);
    }

    /** Every course, grouped by department, for the prerequisite picker: "CSC 101 — Introduction to Computing (3u, 100L)". */
    protected function courseOptions(): array
    {
        return Course::with('department')->orderBy('code')->get()
            ->groupBy(fn ($c) => $c->department?->name ?? 'No department')
            ->sortKeys()
            ->map(fn ($g) => $g->mapWithKeys(fn ($c) => [$c->id => $c->code . ' — ' . $c->title . ' (' . $c->credit_units . 'u' . ($c->level ? ', ' . $c->level . 'L' : '') . ')' . ($c->is_active ? '' : ' [inactive]')])->all())
            ->all();
    }

    /** Prerequisites as course ids, from the picker (ids) or typed codes ("CSC 101, MTH 101"). */
    private function prerequisiteIds(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values()->all();
        }

        return Course::whereIn('code', $this->codes($value))->pluck('id')->all();
    }

    /** Would making $ids prerequisites of the course go round in a circle (A needs B, B needs A)? Returns the course that closes it. */
    private function cycle(int $courseId, array $ids): ?Course
    {
        $seen = [];
        $queue = $ids;
        while ($queue) {
            $id = array_shift($queue);
            if ($id === $courseId) {
                return Course::find($courseId);
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach (\Illuminate\Support\Facades\DB::table('course_prerequisites')->where('course_id', $id)->pluck('prerequisite_id') as $next) {
                $queue[] = (int) $next;
            }
        }

        return null;
    }

    /** Programmes of other departments whose curriculum would break if the course were owned or shared like this. */
    private function brokenCurricula(Model $record, ?int $departmentId, ?string $sharedAs): array
    {
        $allowed = CourseSharing::typesFor($sharedAs);

        return \App\Models\ProgrammeCourse::with('programme')->where('course_id', $record->id)->get()
            ->filter(fn ($pc) => $pc->programme && (int) $pc->programme->department_id !== (int) $departmentId && ! in_array($pc->status, $allowed, true))
            ->map(fn ($pc) => $pc->programme->name . ' (as ' . strtolower(CourseType::label($pc->status)) . ')')
            ->unique()->values()->all();
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
            'course_type'   => ['nullable', Rule::in(array_keys(CourseType::options()))],
            'level'         => ['nullable', Rule::in(array_keys(\App\Support\Levels::options()))],
            'description'   => ['nullable', 'string', 'max:2000'],
            'is_active'     => ['nullable', 'boolean'],
            'shared_as'     => ['nullable', Rule::in(array_keys(array_filter(CourseSharing::options(), fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY))), new \App\Rules\AlwaysCheck(function ($attr, $value, $fail) use ($record, $request) {
                $sharedAs = $request->has('shared_as') ? ($value ?: null) : $record?->shared_as;
                if ($record && ($broken = $this->brokenCurricula($record, (int) $request->input('department_id', $record->department_id), $sharedAs))) {
                    $fail('Other departments already use this course in a way this would not allow: ' . implode(', ', $broken) . '. Change those curriculum entries first, or share the course so they stay allowed.');
                }
            })],
            'prerequisites' => ['nullable', function ($attr, $value, $fail) use ($record) {
                if (is_array($value)) {
                    $ids = $this->prerequisiteIds($value);
                    if (count($ids) !== Course::whereIn('id', $ids)->count()) {
                        $fail('One of the chosen prerequisites no longer exists.');
                    }
                } else {
                    if (strlen((string) $value) > 500) {
                        $fail('The prerequisites list is too long.');
                    }
                    $codes = $this->codes($value);
                    $known = Course::whereIn('code', $codes)->pluck('code')->all();
                    if ($unknown = array_diff($codes, $known)) {
                        $fail('These course codes do not exist: ' . implode(', ', $unknown) . '.');
                    }
                    $ids = Course::whereIn('code', $codes)->pluck('id')->all();
                }
                if ($record && in_array($record->id, $ids, true)) {
                    $fail('A course cannot be its own prerequisite.');
                } elseif ($record && ($loop = $this->cycle($record->id, array_values(array_diff($ids, [$record->id]))))) {
                    $fail('That would go round in a circle: one of the chosen courses already needs ' . $loop->code . ' (directly or through another course).');
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
        $record->prerequisites()->sync(array_values(array_diff($this->prerequisiteIds($request->input('prerequisites')), [$record->id])));
    }

    protected function recordData(Model $row, array $fields): array
    {
        return array_merge(parent::recordData($row, $fields), [
            'faculty_id' => (string) ($row->department?->faculty_id ?? ''),
            'prerequisites' => $row->prerequisites->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
            'shared_as' => (string) ($row->shared_as ?? ''),
        ]);
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        // "csc  101" -> "CSC 101"
        $data['code'] = strtoupper(preg_replace('/\s+/', ' ', trim($data['code'])));
        unset($data['prerequisites'], $data['faculty_id']);
        if (empty($data['course_type'])) {
            $data['course_type'] = $record?->course_type ?: CourseType::COMPULSORY;
        }
        if (array_key_exists('shared_as', $data) || $request->has('shared_as')) {
            $data['shared_as'] = $request->input('shared_as') ?: null;
        }

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
            ['name' => 'course_type', 'label' => 'Type in its own department', 'type' => 'select', 'options' => CourseType::options(), 'required' => true, 'col' => 6, 'default' => CourseType::COMPULSORY, 'help' => 'Compulsory (core), compulsory elective or elective for the owning department\'s programmes.'],
            ['name' => 'shared_as', 'label' => 'Other departments', 'type' => 'select', 'options' => array_filter(CourseSharing::options(), fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY), 'blank' => CourseSharing::options()[''], 'col' => 12, 'default' => '',
                'help' => 'Whether other departments can see this course when they build their curriculum, and the type they may give it. Only a general course (GST and the like) may be compulsory in another department.'],
            ['name' => 'prerequisites', 'label' => 'Prerequisites', 'type' => 'multiselect', 'options' => $this->courseOptions(), 'col' => 12, 'exclude_self' => true,
                'placeholder' => 'Search and tick the courses a student must pass first', 'help' => 'A student must have passed every course ticked here before registering for this course.'],
            ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'col' => 12],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['ID', fn ($r) => new HtmlString('<span class="text-muted fs-12">#' . $r->id . '</span>')],
            ['Code', fn ($r) => new HtmlString('<span class="badge bg-primary-subtle text-primary">' . e($r->code) . '</span>')],
            ['Title', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->title) . '</span>')],
            ['Department', fn ($r) => new HtmlString(e($r->department->name) . '<div class="text-muted fs-12">' . e($r->department->faculty?->name) . '</div>')],
            ['Category', fn ($r) => $r->studyCategory?->name ?? 'All'],
            ['Level', fn ($r) => $r->level ? $r->level . 'L' : '—'],
            ['Semester', fn ($r) => trim(($r->semesterType?->name ?? '—') . ($r->session ? ' · from ' . $r->session->name : ''))],
            ['Units', fn ($r) => $r->credit_units],
            ['Prerequisites', fn ($r) => new HtmlString($r->prerequisites->isEmpty() ? '<span class="text-muted">—</span>'
                : $r->prerequisites->map(fn ($p) => '<span class="badge bg-light text-body border me-1" title="' . e($p->title) . '">' . e($p->code) . '</span>')->implode(''))],
            ['Other departments', fn ($r) => new HtmlString(($r->shared_as
                ? '<span class="badge bg-success-subtle text-success" title="' . e(CourseSharing::options()[$r->shared_as] ?? '') . '">Shared · ' . e(CourseSharing::short($r->shared_as)) . '</span>'
                : '<span class="badge bg-light text-muted border">Own department only</span>')
                . ($r->programme_courses_count ? '<div class="text-muted fs-12">on ' . $r->programme_courses_count . ' curriculum' . ($r->programme_courses_count === 1 ? '' : 's') . '</div>' : ''))],
            ['Type', fn ($r) => new HtmlString(\Illuminate\Support\Facades\Blade::renderComponent(new \App\View\Components\CourseTag($r, department: false, full: true)))],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
