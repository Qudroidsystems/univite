<?php

namespace App\Http\Controllers\Academics;

use App\Models\ResultKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

/**
 * Key to result: what a student's GPA means once results are computed. Each range has the remark printed on the
 * master sheet (PASS, CSO, TOS ...), the student's rank or standing, the action that follows and an activity note.
 */
class ResultKeyController extends CrudController
{
    use Concerns\KeyScopeFields;

    protected string $permission = 'result keys';
    protected string $title = 'Key to Result';
    protected string $singular = 'Result Key';
    protected string $icon = 'ri-key-2-line';
    protected string $routeBase = 'academics.result-keys';
    protected string $intro = 'What a student\'s cumulative GPA means after the Head of Department computes results: the remark printed on the master sheet, the rank (standing), the action that follows and what the student should do. Leave school, department and category blank for the whole university; the most specific key that matches the student\'s department and category is used. Ranges in one key must not overlap.';

    protected function model(): string
    {
        return ResultKey::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('faculty', 'department', 'studyCategory')->reorder()
            ->orderByRaw('department_id is not null, faculty_id is not null, study_category_id is not null')->orderBy('department_id')->orderBy('faculty_id')->orderBy('study_category_id')->orderByDesc('min_gpa');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return $this->scopeRules($request) + [
            'code' => ['required', 'string', 'max:12'],
            'min_gpa' => ['required', 'numeric', 'between:0,10'],
            'max_gpa' => ['required', 'numeric', 'between:0,10', 'gte:min_gpa', function ($attr, $value, $fail) use ($request, $record) {
                $overlap = ResultKey::sameScope(...$this->scopeInput($request))->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                    ->where('min_gpa', '<=', (float) $value)->where('max_gpa', '>=', (float) $request->input('min_gpa'))->first();
                if ($overlap) {
                    $fail("This overlaps {$overlap->code} ({$overlap->min_gpa} to {$overlap->max_gpa}).");
                }
            }],
            'rank' => ['required', 'string', 'max:60'],
            'action' => ['required', 'in:' . implode(',', array_keys(ResultKey::ACTIONS))],
            'activity' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function fields(): array
    {
        return array_merge($this->scopeFields(), [
            ['name' => 'code', 'label' => 'Remark (grade)', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'PASS', 'help' => 'Printed on the master sheet, e.g. PASS, CSO, TOS.'],
            ['name' => 'min_gpa', 'label' => 'From CGPA', 'type' => 'number', 'step' => 0.01, 'required' => true, 'col' => 4],
            ['name' => 'max_gpa', 'label' => 'To CGPA', 'type' => 'number', 'step' => 0.01, 'required' => true, 'col' => 4],
            ['name' => 'rank', 'label' => 'Rank', 'type' => 'text', 'required' => true, 'col' => 6, 'placeholder' => 'Good standing'],
            ['name' => 'action', 'label' => 'Action', 'type' => 'select', 'options' => ResultKey::ACTIONS, 'required' => true, 'col' => 6, 'default' => 'proceed'],
            ['name' => 'activity', 'label' => 'Activity', 'type' => 'textarea', 'col' => 12, 'placeholder' => 'Register the outstanding courses next session', 'help' => 'What the student should do. Shown in the key on the master sheet.'],
        ]);
    }

    protected function columns(): array
    {
        $n = fn ($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');

        return [
            ['Applies to', fn ($r) => $r->coverageLabel()],
            ['Remark', fn ($r) => new HtmlString('<span class="badge bg-primary-subtle text-primary fs-13">' . e($r->code) . '</span>')],
            ['CGPA range', fn ($r) => $n($r->min_gpa) . ' – ' . $n($r->max_gpa)],
            ['Rank', fn ($r) => $r->rank],
            ['Action', fn ($r) => $r->actionLabel()],
            ['Activity', fn ($r) => $r->activity ?: '—'],
        ];
    }
}
