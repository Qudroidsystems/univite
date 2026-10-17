<?php

namespace App\Http\Controllers\Academics;

use App\Models\ResultSheet;
use App\Models\ResultStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class ResultStageController extends CrudController
{
    protected string $permission = 'result stages';
    protected string $title = 'Result Approval Steps';
    protected string $singular = 'Step';
    protected string $icon = 'ri-git-merge-line';
    protected string $routeBase = 'academics.result-stages';
    protected string $intro = 'After a lecturer submits a score sheet it goes through these steps in order, for example Head of Department then Dean, then Senate. Choose the role that signs off each step, or leave the role blank to let anyone allowed to approve results sign it. The last step is followed by publishing, which makes results visible to students. Remove all steps to publish straight after submission.';
    protected array $snapshotSections = ['approval'];

    protected function model(): string
    {
        return ResultStage::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderBy('sort_order')->orderBy('id');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('result_stages', 'name')->ignore($record?->id)],
            'office_role' => ['nullable', Rule::in(Role::pluck('name')->all())],
            'sort_order' => ['required', 'integer', 'between:1,99'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Step name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Senate'],
            ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'required' => true, 'col' => 4, 'default' => 1],
            ['name' => 'office_role', 'label' => 'Role that signs this step', 'type' => 'select', 'options' => Role::orderBy('name')->pluck('name', 'name')->all(), 'blank' => 'Anyone who can approve results', 'col' => 12],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Order', fn ($r) => $r->sort_order],
            ['Step', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>')],
            ['Signed by', fn ($r) => $r->office_role ?: 'Anyone who can approve results'],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Off</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        return ResultSheet::where('stage_id', $record->id)->exists() ? 'Score sheets are waiting at this step. Switch it off instead, or move them on first.' : null;
    }
}
