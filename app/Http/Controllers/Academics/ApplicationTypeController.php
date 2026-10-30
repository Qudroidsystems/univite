<?php

namespace App\Http\Controllers\Academics;

use App\Models\Applicant;
use App\Models\ApplicationType;
use App\Models\StudyCategory;
use App\Services\ApplicationSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/** The kinds of application open to the public, e.g. Degree (UTME), PDE, NCE Part-Time, each with its own fee and dates. */
class ApplicationTypeController extends CrudController
{
    protected string $permission = 'application types';
    protected string $title = 'Application Types';
    protected string $singular = 'Application type';
    protected string $icon = 'ri-file-list-2-line';
    protected string $routeBase = 'applicants.types';
    protected string $intro = 'Run several kinds of application at once, for example Degree (UTME), PDE and NCE Part-Time. Each can have its own screening fee, dates and student category; applicants pick one when they apply. With none set, everyone applies the one way set under Application Settings.';
    protected array $searchable = ['name'];

    protected function model(): string
    {
        return ApplicationType::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('studyCategory')->reorder()->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('application_types')->ignore($record?->id)],
            'study_category_id' => ['nullable', 'exists:study_categories,id'],
            'fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'opens_on' => ['nullable', 'date'],
            'closes_on' => ['nullable', 'date', 'after_or_equal:opens_on'],
            'needs_jamb' => ['nullable', 'boolean'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $data['fee'] = isset($data['fee']) && $data['fee'] !== '' ? $data['fee'] : null;
        $data['study_category_id'] = ($data['study_category_id'] ?? null) ?: null;

        return $data;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'PDE Application'],
            ['name' => 'study_category_id', 'lookup' => 'study-categories', 'label' => 'Programmes of', 'type' => 'select', 'options' => StudyCategory::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'Every category', 'col' => 4],
            ['name' => 'fee', 'label' => 'Screening fee (₦)', 'type' => 'number', 'col' => 4, 'step' => '0.01', 'help' => 'Blank = ₦' . number_format(ApplicationSettings::fee(), 2) . ', the fee under Application Settings.'],
            ['name' => 'opens_on', 'label' => 'Opens', 'type' => 'date', 'col' => 4],
            ['name' => 'closes_on', 'label' => 'Closes', 'type' => 'date', 'col' => 4],
            ['name' => 'needs_jamb', 'label' => 'Applicants must give a JAMB registration number', 'type' => 'switch', 'col' => 12, 'default' => true],
            ['name' => 'instructions', 'label' => 'Instructions shown to these applicants', 'type' => 'textarea', 'col' => 12],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Application type', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span><div class="text-muted fs-12">' . e($r->studyCategory?->name ?? 'Every category') . ($r->needs_jamb ? ' · JAMB number needed' : '') . '</div>')],
            ['Screening fee', fn ($r) => '₦' . number_format($r->screeningFee(), 2) . ($r->fee === null ? ' (default)' : '')],
            ['Dates', fn ($r) => $r->opens_on || $r->closes_on ? ($r->opens_on?->format('j M Y') ?? 'Now') . ' to ' . ($r->closes_on?->format('j M Y') ?? 'no deadline') : 'While applications are open'],
            ['Applicants', fn ($r) => Applicant::where('application_type_id', $r->id)->count()],
            ['Status', fn ($r) => new HtmlString($r->isOpen() ? '<span class="badge bg-success-subtle text-success">Open</span>' : '<span class="badge bg-secondary-subtle text-secondary">' . ($r->is_active ? 'Not open now' : 'Inactive') . '</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        return Applicant::where('application_type_id', $record->id)->exists() ? 'Applicants have applied under this type. Switch it off instead.' : null;
    }
}
