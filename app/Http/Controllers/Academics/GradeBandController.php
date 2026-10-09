<?php

namespace App\Http\Controllers\Academics;

use App\Models\CourseResult;
use App\Models\GradeBand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

class GradeBandController extends CrudController
{
    protected string $permission = 'grade bands';
    protected string $title = 'Grading Scale';
    protected string $singular = 'Grade';
    protected string $icon = 'ri-bar-chart-grouped-line';
    protected string $routeBase = 'academics.grade-bands';
    protected ?string $extraView = 'academics.partials.grade-gaps';
    protected string $intro = 'Score bands for your grades: the letter, the grade points and whether it is a pass. Bands must not overlap and should cover 0 to 100. Changing the scale does not regrade results that are already approved.';

    protected function model(): string
    {
        return GradeBand::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderByDesc('min_score');
    }

    protected function viewData(Request $request): array
    {
        $bands = GradeBand::orderBy('min_score')->get();
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
        return [
            'letter'    => ['required', 'string', 'max:5'],
            'min_score' => ['required', 'numeric', 'between:0,100'],
            'max_score' => ['required', 'numeric', 'between:0,100', 'gte:min_score', function ($attr, $value, $fail) use ($request, $record) {
                $overlap = GradeBand::when($record, fn ($q) => $q->where('id', '!=', $record->id))
                    ->where('min_score', '<=', (float) $value)->where('max_score', '>=', (float) $request->input('min_score'))->first();
                if ($overlap) {
                    $fail("This overlaps the {$overlap->letter} band ({$overlap->min_score} to {$overlap->max_score}).");
                }
            }],
            'points'    => ['required', 'numeric', 'between:0,10'],
            'remark'    => ['nullable', 'string', 'max:60'],
            'is_pass'   => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'letter', 'label' => 'Grade letter', 'type' => 'text', 'required' => true, 'col' => 4, 'placeholder' => 'A'],
            ['name' => 'min_score', 'label' => 'From score', 'type' => 'number', 'step' => 0.01, 'required' => true, 'col' => 4],
            ['name' => 'max_score', 'label' => 'To score', 'type' => 'number', 'step' => 0.01, 'required' => true, 'col' => 4],
            ['name' => 'points', 'label' => 'Grade points', 'type' => 'number', 'step' => 0.01, 'required' => true, 'col' => 4],
            ['name' => 'remark', 'label' => 'Remark', 'type' => 'text', 'col' => 8, 'placeholder' => 'Excellent'],
            ['name' => 'is_pass', 'label' => 'This grade is a pass', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Grade', fn ($r) => new HtmlString('<span class="badge bg-primary-subtle text-primary fs-13">' . e($r->letter) . '</span>')],
            ['Scores', fn ($r) => rtrim(rtrim(number_format($r->min_score, 2), '0'), '.') . ' – ' . rtrim(rtrim(number_format($r->max_score, 2), '0'), '.')],
            ['Points', fn ($r) => rtrim(rtrim(number_format($r->points, 2), '0'), '.')],
            ['Remark', fn ($r) => $r->remark ?: '—'],
            ['Result', fn ($r) => new HtmlString($r->is_pass ? '<span class="badge bg-success-subtle text-success">Pass</span>' : '<span class="badge bg-danger-subtle text-danger">Fail</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        return CourseResult::where('grade', $record->letter)->exists() ? 'Results already use this grade. Edit it instead of deleting it.' : null;
    }
}
