<?php

namespace App\Http\Controllers\Academics;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ResultSignatory;
use App\Models\StudyCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

/** The names, titles and positions printed under master sheets and transcripts, per faculty, department, category or programme. */
class ResultSignatoryController extends CrudController
{
    protected string $permission = 'result signatories';
    protected string $title = 'Result Signatories';
    protected string $singular = 'Signatory';
    protected string $icon = 'ri-quill-pen-line';
    protected string $routeBase = 'academics.result-signatories';
    protected string $intro = 'Who signs printed results, for example "Dr. J. Edafe, Head of Department" for Economics. Leave the faculty, department, category and programme on All for a signatory used everywhere; a more specific one with the same position takes its place. With none set, the master sheet shows a line per approval step.';
    protected array $searchable = ['name', 'position'];

    protected function model(): string
    {
        return ResultSignatory::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('faculty', 'department', 'studyCategory', 'programme')->reorder()->orderBy('sort_order')->orderBy('position');
    }

    protected function filters(): array
    {
        return [['department_id', 'Department', Department::orderBy('name')->pluck('name', 'id')->all()]];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'faculty_id' => ['nullable', 'exists:faculties,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'study_category_id' => ['nullable', 'exists:study_categories,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'position' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:99'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        foreach (ResultSignatory::SCOPES as $col) {
            $data[$col] = ($data[$col] ?? null) ?: null;
        }
        $data['sort_order'] = (int) ($data['sort_order'] ?? 1) ?: 1;

        return $data;
    }

    protected function fields(): array
    {
        $all = ['blank' => 'All'];

        return [
            ['name' => 'faculty_id', 'lookup' => 'faculties', 'label' => 'Faculty / School', 'type' => 'select', 'options' => Faculty::orderBy('name')->pluck('name', 'id')->all(), 'col' => 6] + $all,
            ['name' => 'department_id', 'lookup' => 'departments', 'lookup_parent' => 'faculty_id', 'label' => 'Department', 'type' => 'select', 'options' => Department::orderBy('name')->pluck('name', 'id')->all(), 'col' => 6] + $all,
            ['name' => 'study_category_id', 'lookup' => 'study-categories', 'label' => 'Student category', 'type' => 'select', 'options' => StudyCategory::orderBy('name')->pluck('name', 'id')->all(), 'col' => 6] + $all,
            ['name' => 'programme_id', 'lookup' => 'programmes', 'lookup_parent' => 'department_id', 'label' => 'Programme', 'type' => 'select', 'options' => Programme::orderBy('name')->pluck('name', 'id')->all(), 'col' => 6] + $all,
            ['name' => 'position', 'label' => 'Position', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Head of Department'],
            ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'col' => 4, 'default' => 1, 'help' => 'Left to right on the page.'],
            ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'col' => 3, 'placeholder' => 'Dr.'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'col' => 9, 'placeholder' => 'J. Edafe', 'help' => 'Leave blank to print a line for the name.'],
            ['name' => 'address', 'label' => 'Under the position', 'type' => 'text', 'col' => 12, 'placeholder' => 'Department of Economics'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Order', fn ($r) => $r->sort_order],
            ['Signatory', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->fullName() ?: '(name left blank)') . '</span><div class="text-muted fs-12">' . e($r->position) . ($r->address ? ', ' . e($r->address) : '') . '</div>')],
            ['For', fn ($r) => $r->scopeLabel()],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
