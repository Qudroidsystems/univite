<?php

namespace App\Http\Controllers\Academics;

use App\Models\CourseResult;
use App\Models\GradeBand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

/** Key to grade: score ranges with their letter, points and rank, for the university or one school, department or category. */
class GradeBandController extends CrudController
{
    use Concerns\KeyScopeFields;

    protected string $permission = 'grade bands';
    protected string $title = 'Key to Grade';
    protected string $singular = 'Grade';
    protected string $icon = 'ri-bar-chart-grouped-line';
    protected string $routeBase = 'academics.grade-bands';
    protected ?string $extraView = 'academics.partials.grade-gaps';
    protected string $intro = 'Score ranges for each grade: the letter, grade points, rank (Distinction, Credit, Merit, Pass, Fail) and whether it is a pass. Leave school, department and category blank for the whole university. A school, department or student category can have its own key: when results are computed, the most specific key that matches the course\'s department and the student\'s category is used, never a mix. Ranges in one key must not overlap and should cover 0 to 100. Changing a key does not regrade results that are already approved.';
    protected array $snapshotSections = ['grading'];

    protected function model(): string
    {
        return GradeBand::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('faculty', 'department', 'studyCategory')->reorder()
            ->orderByRaw('department_id is not null, faculty_id is not null, study_category_id is not null')->orderBy('department_id')->orderBy('faculty_id')->orderBy('study_category_id')->orderByDesc('min_score');
    }

    protected function viewData(Request $request): array
    {
        $bands = GradeBand::sameScope(null, null, null)->orderBy('min_score')->get();   // gaps in the university-wide key
        $gaps = [];
        $expect = 0.0;
        foreach ($bands as $b) {
            if ($b->min_score - $expect > 0.011) {
                $gaps[] = number_format($expect, 2) . ' to ' . number_format($b->min_score - 0.01, 2);
            }
            $expect = max($expect, $b->max_score + 0.01);
        }
        if (100 - $expect > 0.011) {
            $gaps[] = number_format($expect, 2) . ' to 100';
        }

        return ['gaps' => $gaps];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return $this->scopeRules($request) + [
            'letter'    => ['required', 'string', 'max:5', function ($attr, $value, $fail) use ($request, $record) {
                if (GradeBand::sameScope(...$this->scopeInput($request))->when($record, fn ($q) => $q->where('id', '!=', $record->id))->where('letter', $value)->exists()) {
                    $fail("Grade {$value} is already in this set. Edit that band instead.");
                }
            }],
            'min_score' => ['required', 'numeric', 'between:0,100'],
            'max_score' => ['required', 'numeric', 'between:0,100', 'gte:min_score', function ($attr, $value, $fail) use ($request, $record) {
                $overlap = GradeBand::sameScope(...$this->scopeInput($request))->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                    ->where('min_score', '<=', (float) $value)->where('max_score', '>=', (float) $request->input('min_score'))->first();
                if ($overlap) {
                    $fail("This overlaps the {$overlap->letter} band ({$overlap->min_score} to {$overlap->max_score}).");
                }
            }],
            'points'    => ['required', 'numeric', 'between:0,10'],
            'rank'      => ['nullable', 'string', 'max:40'],
            'remark'    => ['nullable', 'string', 'max:60'],
            'is_pass'   => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return array_merge($this->scopeFields(), [
            ['name' => 'letter', 'label' => 'Grade letter', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'A'],
            ['name' => 'min_score', 'label' => 'From score', 'type' => 'number', 'step' => 0.01, 'required' => true, 'col' => 4],
            ['name' => 'max_score', 'label' => 'To score', 'type' => 'number', 'step' => 0.01, 'required' => true, 'col' => 4],
            ['name' => 'points', 'label' => 'Grade points', 'type' => 'number', 'step' => 0.01, 'required' => true, 'col' => 4],
            ['name' => 'rank', 'label' => 'Rank', 'type' => 'select', 'options' => array_combine(GradeBand::RANKS, GradeBand::RANKS), 'blank' => '—', 'col' => 4],
            ['name' => 'remark', 'label' => 'Remark', 'type' => 'text', 'col' => 4, 'placeholder' => 'Excellent'],
            ['name' => 'is_pass', 'label' => 'This grade is a pass', 'type' => 'switch', 'col' => 12, 'default' => true],
        ]);
    }

    protected function columns(): array
    {
        return [
            ['Applies to', fn ($r) => $r->coverageLabel()],
            ['Grade', fn ($r) => new HtmlString('<span class="badge bg-primary-subtle text-primary fs-13">' . e($r->letter) . '</span>')],
            ['Scores', fn ($r) => rtrim(rtrim(number_format($r->min_score, 2), '0'), '.') . ' – ' . rtrim(rtrim(number_format($r->max_score, 2), '0'), '.')],
            ['Points', fn ($r) => rtrim(rtrim(number_format($r->points, 2), '0'), '.')],
            ['Rank', fn ($r) => $r->rank ?: '—'],
            ['Remark', fn ($r) => $r->remark ?: '—'],
            ['Result', fn ($r) => new HtmlString($r->is_pass ? '<span class="badge bg-success-subtle text-success">Pass</span>' : '<span class="badge bg-danger-subtle text-danger">Fail</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        $another = GradeBand::where('id', '!=', $record->id)->where('letter', $record->letter)->exists();

        return ! $another && CourseResult::where('grade', $record->letter)->exists() ? 'Results already use this grade. Edit it instead of deleting it.' : null;
    }
}
