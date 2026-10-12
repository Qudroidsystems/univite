<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Leave\PolicyCrud;
use App\Models\SalaryStructure;
use App\Models\StaffProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class SalaryStructureController extends PolicyCrud
{
    protected string $permission = 'salary structures';
    protected string $gate = 'Set payroll policy';
    protected array $viewers = ['View payroll'];
    protected string $title = 'Salary Structures';
    protected string $singular = 'Salary structure';
    protected string $icon = 'ri-stack-line';
    protected string $routeBase = 'payroll.structures';
    protected string $intro = 'Each structure has grade levels and steps. Open "Salary scale" to enter the annual basic salary for every grade and step, by hand or from a spreadsheet.';

    protected function model(): string
    {
        return SalaryStructure::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->withCount('scales')->reorder()->orderBy('code');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('salary_structures', 'code')->ignore($record?->id)],
            'name' => ['required', 'string', 'max:150'], 'max_grade' => ['required', 'integer', 'between:1,20'], 'max_step' => ['required', 'integer', 'between:1,20'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'required' => true, 'col' => 4, 'placeholder' => 'CONUASS'],
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'col' => 8],
            ['name' => 'max_grade', 'label' => 'Highest grade level', 'type' => 'number', 'required' => true, 'col' => 4, 'default' => 15],
            ['name' => 'max_step', 'label' => 'Highest step', 'type' => 'number', 'required' => true, 'col' => 4, 'default' => 15],
            ['name' => 'is_active', 'label' => 'In use', 'type' => 'switch', 'default' => true, 'col' => 4],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $data['code'] = strtoupper($data['code']);

        return $data;
    }

    protected function blockDelete(Model $record): ?string
    {
        return StaffProfile::where('salary_structure_id', $record->id)->exists() ? 'Staff are on this structure. Move them first, or mark it not in use.' : null;
    }

    protected function viewData(Request $request): array
    {
        $can = $request->user()->can($this->gate);

        return ['abilities' => ['create' => $can, 'edit' => $can, 'delete' => $can]];
    }

    protected function columns(): array
    {
        return [
            ['Structure', fn ($r) => new HtmlString('<strong>' . e($r->code) . '</strong><div class="text-muted fs-12">' . e($r->name) . '</div>')],
            ['Grades and steps', fn ($r) => "1–{$r->max_grade} · steps 1–{$r->max_step}"],
            ['Salary scale', fn ($r) => new HtmlString('<a href="' . route('payroll.scales.show', $r) . '" class="btn btn-sm btn-soft-primary">' . $r->scales_count . ' amount(s) set</a>')],
            ['In use', fn ($r) => $r->is_active ? 'Yes' : 'No'],
        ];
    }
}
