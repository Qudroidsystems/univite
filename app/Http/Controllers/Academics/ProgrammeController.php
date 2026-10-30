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
    protected string $intro = 'A programme is a degree or diploma a department awards, e.g. B.Sc. Computer Science. Its duration decides the levels it runs (100 to 400, 500 ...). Its admission, graduating and Senate levels and its credit load are set under Academic Rules (Levels).';
    protected array $searchable = ['name', 'code'];

    public function __construct(private \App\Support\AcademicRules $academicRules)
    {
        parent::__construct();
    }

    protected function model(): string
    {
        return Programme::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('department.faculty', 'studyCategory', 'subDepartment', 'secondDepartment')->reorder()->orderBy('name');
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
            'is_active'       => ['nullable', 'boolean'],
            'sub_department_id' => ['nullable', Rule::exists('sub_departments', 'id')->where('department_id', $request->input('department_id'))],
            'is_combination'  => ['nullable', 'boolean', function ($a, $v, $fail) use ($request) {
                $span = \App\Models\DepartmentCategory::for((int) $request->input('department_id'), (int) $request->input('study_category_id'));
                if ($v && $span && ! $span->allow_combination) {
                    $fail('This department does not offer combined programmes in this category. Switch on "Allow combined programmes" under Department Categories first.');
                }
            }],
            'second_department_id' => ['nullable', 'required_if_accepted:is_combination', 'exists:departments,id', 'different:department_id'],
            'show_on_application' => ['nullable', 'boolean'],
            'application_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    protected function messages(): array
    {
        return [
            'sub_department_id.exists' => 'Pick a sub-department of the programme\'s own department.',
            'second_department_id.required_if_accepted' => 'Pick the second department of the combined programme.',
            'second_department_id.different' => 'The second department must be a different department.',
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $data['code'] = strtoupper($data['code']);
        if (empty($data['is_combination'])) {
            $data['second_department_id'] = null;
        }

        return $data;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'department_id', 'lookup' => 'departments', 'label' => 'Department', 'type' => 'select', 'options' => $this->departmentOptions(), 'required' => true, 'col' => 12],
            ['name' => 'name', 'label' => 'Programme name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'B.Sc. Computer Science'],
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'BSC-CSC'],
            ['name' => 'study_category_id', 'lookup' => 'study-categories', 'lookup_active' => true, 'label' => 'Study category', 'type' => 'select', 'options' => \App\Models\StudyCategory::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6, 'help' => 'Degree, NCE, Part-time ... set up under Study Categories.'],
            ['name' => 'degree_type', 'label' => 'Qualification awarded', 'type' => 'text', 'required' => true, 'col' => 6, 'placeholder' => 'B.Sc. (Ed.)'],
            ['name' => 'duration_years', 'label' => 'Duration (years)', 'type' => 'number', 'required' => true, 'col' => 6, 'default' => 4],
            ['name' => 'sub_department_id', 'lookup' => 'sub-departments', 'lookup_parent' => 'department_id', 'label' => 'Sub-department', 'type' => 'select', 'options' => \App\Models\SubDepartment::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'None', 'col' => 6, 'help' => 'Optional, e.g. Christian Religious Studies inside Religious Studies.'],
            ['name' => 'application_name', 'label' => 'Name on the application form', 'type' => 'text', 'col' => 6, 'placeholder' => 'B.A.Ed. Religious Studies', 'help' => 'How applicants see it. Blank = the programme name.'],
            ['name' => 'is_combination', 'label' => 'Combined programme with another department (e.g. Social Studies/English)', 'type' => 'switch', 'col' => 12],
            ['name' => 'second_department_id', 'lookup' => 'departments', 'label' => 'Second department', 'type' => 'select', 'options' => $this->departmentOptions(), 'blank' => 'None', 'col' => 12, 'help' => 'Only for combined programmes.'],
            ['name' => 'show_on_application', 'label' => 'Show on the online application form', 'type' => 'switch', 'col' => 12, 'default' => true],
            ['name' => 'is_active', 'label' => 'Active (accepting students)', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Code', fn ($r) => new HtmlString('<span class="badge bg-light text-body border">' . e($r->code) . '</span>')],
            ['Programme', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>'
                . ($r->is_combination ? ' <span class="badge bg-info-subtle text-info">Combined</span>' : '')
                . '<div class="text-muted fs-12">' . e($r->department->name) . ($r->secondDepartment ? ' / ' . e($r->secondDepartment->name) : '') . ($r->subDepartment ? ' · ' . e($r->subDepartment->name) : '') . ' · ' . e($r->department->faculty->name) . '</div>'
                . ($r->application_name || ! $r->show_on_application ? '<div class="fs-12">' . ($r->show_on_application ? 'Applicants see: ' . e($r->applicationLabel()) : '<span class="text-warning">Not on the application form</span>') . '</div>' : ''))],
            ['Category', fn ($r) => new HtmlString(e($r->studyCategory?->name ?? '—') . '<div class="text-muted fs-12">' . e($r->degree_type) . '</div>')],
            ['Duration', fn ($r) => $r->duration_years . ' yr' . ($r->duration_years > 1 ? 's' : '')],
            ['Levels', function ($r) {
                $rules = $this->academicRules;

                return new HtmlString('<div class="fs-12 lh-sm"><span class="text-muted">Admits:</span> ' . e(implode(', ', $rules->admissionLevels($r)))
                    . '<br><span class="text-muted">Graduates:</span> ' . $rules->graduatingLevel($r)
                    . '<br><span class="text-muted">Senate:</span> ' . e(implode(', ', $rules->senateLevels($r))) . '</div>');
            }],
            ['Rules', fn ($r) => new HtmlString('<a href="' . route('academics.rules.index', ['scope' => 'programme', 'id' => $r->id]) . '" class="fs-13">Credit load, levels &amp; graduation</a>')],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
