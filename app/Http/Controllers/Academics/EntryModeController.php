<?php

namespace App\Http\Controllers\Academics;

use App\Models\Applicant;
use App\Models\EntryMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class EntryModeController extends CrudController
{
    protected string $permission = 'entry modes';
    protected string $title = 'Entry Modes';
    protected string $singular = 'Entry mode';
    protected string $icon = 'ri-door-open-line';
    protected string $routeBase = 'academics.entry-modes';
    protected string $intro = 'The ways a candidate can enter your university (UTME, Direct Entry, Transfer, Postgraduate ...). Each says what level the candidate starts at, whether an entrance-exam score is asked for, whether a prior NCE/ND result is needed, and whether people can apply for it on the public application site.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return EntryMode::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderBy('sort_order');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'code'               => $record ? ['prohibited'] : ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::unique('entry_modes', 'code')],
            'name'               => ['required', 'string', 'max:100'],
            'entry_level'        => ['required', 'integer', 'between:100,800', 'multiple_of:100'],
            'sort_order'         => ['required', 'integer', 'between:0,999'],
            'fixed_level'        => ['nullable', 'boolean'],
            'uses_utme'          => ['nullable', 'boolean'],
            'needs_prior_result' => ['nullable', 'boolean'],
            'open_online'        => ['nullable', 'boolean'],
            'is_active'          => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 6, 'placeholder' => 'Direct Entry'],
            ['name' => 'code', 'label' => 'Code (cannot change later)', 'type' => 'text', 'required' => true, 'col' => 6, 'only' => 'create', 'placeholder' => 'direct_entry', 'help' => 'Lowercase letters, numbers and underscores.'],
            ['name' => 'entry_level', 'label' => 'Entry level', 'type' => 'select', 'options' => \App\Support\Levels::options(), 'required' => true, 'col' => 6, 'default' => 100],
            ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'required' => true, 'col' => 6, 'default' => 10],
            ['name' => 'fixed_level', 'label' => 'Must enter exactly at this level (otherwise this level or higher)', 'type' => 'switch', 'col' => 12],
            ['name' => 'uses_utme', 'label' => 'Candidate has an entrance-exam (UTME) score', 'type' => 'switch', 'col' => 12],
            ['name' => 'needs_prior_result', 'label' => 'Needs a prior NCE / ND result', 'type' => 'switch', 'col' => 12],
            ['name' => 'open_online', 'label' => 'Open on the public application site', 'type' => 'switch', 'col' => 12],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        $yes = fn ($v) => new HtmlString($v ? '<i class="ri-check-line text-success"></i>' : '<span class="text-muted">—</span>');

        return [
            ['Entry mode', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span><div class="text-muted fs-12">' . e($r->code) . '</div>')],
            ['Entry level', fn ($r) => ($r->fixed_level ? '' : '≥ ') . $r->entry_level],
            ['Exam score', fn ($r) => $yes($r->uses_utme)],
            ['Prior NCE/ND', fn ($r) => $yes($r->needs_prior_result)],
            ['Apply online', fn ($r) => $yes($r->open_online)],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        return Applicant::where('entry_mode', $record->code)->exists() ? 'Applicants already used this entry mode. Mark it inactive instead.' : null;
    }
}
