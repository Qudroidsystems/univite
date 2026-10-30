<?php

namespace App\Http\Controllers\Academics;

use App\Models\AcademicSession;
use App\Models\Department;
use App\Models\EntryMode;
use App\Models\Faculty;
use App\Models\MatricFormat;
use App\Models\Programme;
use App\Models\StudyCategory;
use App\Services\MatricNumberGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

/**
 * Matric number formats for parts of the school, as the old portal's Matric Number Settings: e.g. Religious Studies
 * degree students by UTME get REL/2025/0001U, by direct entry REL/2025/0001D. Students no format matches use the
 * school-wide pattern under Matric Number Format.
 */
class MatricFormatController extends CrudController
{
    protected string $permission = 'matric formats';
    protected string $title = 'Matric Number Formats';
    protected string $singular = 'Matric number format';
    protected string $icon = 'ri-hashtag';
    protected string $routeBase = 'students.matric-formats';
    protected string $intro = 'A different matric number format for a faculty, department, study category, programme or entry mode. When several match a student, the most specific wins (a programme over a department over a faculty; an entry mode or category makes it more specific). Students no format matches get the school-wide format. {PREFIX} and {SUFFIX} print the prefix and suffix set here, e.g. REL and U for UTME or D for direct entry. Numbers start at "First number" and stop at "Last number".';
    protected array $searchable = ['name', 'pattern', 'prefix'];

    public function __construct(private MatricNumberGenerator $generator)
    {
        parent::__construct();
    }

    protected function model(): string
    {
        return MatricFormat::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('faculty', 'department', 'studyCategory', 'programme')->reorder()->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'faculty_id' => ['nullable', 'exists:faculties,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'study_category_id' => ['nullable', 'exists:study_categories,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'entry_mode' => ['nullable', 'exists:entry_modes,code'],
            'pattern' => ['required', 'string', 'max:60', function ($a, $v, $fail) {
                if ($e = MatricNumberGenerator::validate((string) $v)) {
                    $fail($e);
                }
            }],
            'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]*$/'],
            'suffix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]*$/'],
            'serial_from' => ['required', 'integer', 'min:1', 'max:99999999'],
            'serial_to' => ['nullable', 'integer', 'gt:serial_from', 'max:99999999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function messages(): array
    {
        return ['prefix.regex' => 'Use letters, numbers and dashes only.', 'suffix.regex' => 'Use letters, numbers and dashes only.', 'serial_to.gt' => 'The last number must be above the first.'];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        foreach (['prefix', 'suffix'] as $k) {
            $data[$k] = isset($data[$k]) && $data[$k] !== '' ? strtoupper($data[$k]) : null;
        }

        return $data;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 12, 'placeholder' => 'Religious Studies, UTME'],
            ['name' => 'faculty_id', 'lookup' => 'faculties', 'label' => 'Faculty / school', 'type' => 'select', 'options' => Faculty::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'Any', 'col' => 6],
            ['name' => 'department_id', 'lookup' => 'departments', 'label' => 'Department', 'type' => 'select', 'options' => Department::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'Any', 'col' => 6],
            ['name' => 'study_category_id', 'lookup' => 'study-categories', 'label' => 'Study category', 'type' => 'select', 'options' => StudyCategory::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'Any', 'col' => 6],
            ['name' => 'entry_mode', 'lookup' => 'entry-modes', 'label' => 'Entry mode', 'type' => 'select', 'options' => EntryMode::orderBy('sort_order')->pluck('name', 'code')->all(), 'blank' => 'Any', 'col' => 6],
            ['name' => 'programme_id', 'lookup' => 'programmes', 'label' => 'Programme', 'type' => 'select', 'options' => Programme::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'Any', 'col' => 12],
            ['name' => 'pattern', 'label' => 'Format', 'type' => 'text', 'required' => true, 'col' => 12, 'default' => '{PREFIX}/{YEAR}/{SEQ:4}{SUFFIX}',
                'help' => 'Parts: ' . implode(', ', array_map(fn ($t) => '{' . $t . '}', array_keys(MatricNumberGenerator::TOKENS))) . '. Exactly one {SEQ:n}.'],
            ['name' => 'prefix', 'label' => 'Prefix', 'type' => 'text', 'col' => 3, 'placeholder' => 'REL'],
            ['name' => 'suffix', 'label' => 'Suffix', 'type' => 'text', 'col' => 3, 'placeholder' => 'U'],
            ['name' => 'serial_from', 'label' => 'First number', 'type' => 'number', 'required' => true, 'col' => 3, 'default' => 1],
            ['name' => 'serial_to', 'label' => 'Last number', 'type' => 'number', 'col' => 3, 'placeholder' => 'No limit'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        $session = AcademicSession::current() ?? AcademicSession::orderByDesc('name')->first();

        return [
            ['Format', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span><div class="text-muted fs-12"><code>' . e($r->pattern) . '</code></div>')],
            ['Applies to', fn ($r) => implode(' · ', array_filter([$r->faculty?->name, $r->department?->name, $r->studyCategory?->name, $r->programme?->name, $r->entry_mode ? 'Entry: ' . $r->entry_mode : null])) ?: 'Everyone'],
            ['Range', fn ($r) => $r->serial_from . ' to ' . ($r->serial_to ?: 'no limit')],
            ['Next number (example)', function ($r) use ($session) {
                $p = $r->programme ?? Programme::query()
                    ->when($r->department_id, fn ($q) => $q->where('department_id', $r->department_id))
                    ->when($r->faculty_id, fn ($q) => $q->whereHas('department', fn ($d) => $d->where('faculty_id', $r->faculty_id)))
                    ->when($r->study_category_id, fn ($q) => $q->where('study_category_id', $r->study_category_id))->first();

                return $p && $session ? new HtmlString('<code>' . e($this->generator->preview($p, $session, entryMode: $r->entry_mode)) . '</code><div class="text-muted fs-12">' . e($p->name) . '</div>') : '—';
            }],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }
}
