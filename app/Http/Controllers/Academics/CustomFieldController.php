<?php

namespace App\Http\Controllers\Academics;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class CustomFieldController extends CrudController
{
    protected string $permission = 'custom fields';
    protected string $title = 'Custom Form Fields';
    protected string $singular = 'Field';
    protected string $icon = 'ri-input-method-line';
    protected string $routeBase = 'academics.custom-fields';
    protected string $intro = 'Add your own questions to the applicant, student and staff forms: short or long text, numbers, dates, a choice from a list, yes/no, or a file upload. New fields appear on the forms straight away. Turn on "Person fills it in" to ask applicants and new students themselves.';
    protected array $searchable = ['label', 'key', 'section'];

    protected function model(): string
    {
        return CustomField::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderBy('target')->orderBy('sort_order')->orderBy('id');
    }

    protected function filters(): array
    {
        return [['target', 'Form', CustomField::TARGETS]];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $locked = fn ($attr, $value, $fail) => $record && $record->values()->exists() && $value !== $record->{$attr}
            ? $fail('People have already answered this field, so its ' . ($attr === 'type' ? 'type' : 'form') . ' cannot be changed. Add a new field instead.') : null;

        return [
            'target'       => ['required', Rule::in(array_keys(CustomField::TARGETS)), $locked],
            'label'        => ['required', 'string', 'max:120'],
            'type'         => ['required', Rule::in(array_keys(CustomField::TYPES)), $locked],
            'options_text' => [Rule::requiredIf($request->input('type') === 'select'), 'nullable', 'string', 'max:3000'],
            'section'      => ['nullable', 'string', 'max:80'],
            'help_text'    => ['nullable', 'string', 'max:200'],
            'accept'       => ['nullable', 'string', 'regex:/^[a-z0-9]+(,[a-z0-9]+)*$/i', 'max:80'],
            'max_kb'       => ['nullable', 'integer', 'between:50,20480'],
            'sort_order'   => ['nullable', 'integer', 'between:0,999'],
            'is_required'  => ['nullable', 'boolean'],
            'self_service' => ['nullable', 'boolean'],
            'is_active'    => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'target', 'label' => 'Which form', 'type' => 'select', 'options' => CustomField::TARGETS, 'required' => true, 'col' => 4, 'default' => 'student'],
            ['name' => 'label', 'label' => 'Question / field label', 'type' => 'text', 'required' => true, 'col' => 5, 'placeholder' => 'Next of kin occupation'],
            ['name' => 'type', 'label' => 'Answer type', 'type' => 'select', 'options' => CustomField::TYPES, 'required' => true, 'col' => 3, 'default' => 'text'],
            ['name' => 'options_text', 'label' => 'Choices (one per line)', 'type' => 'textarea', 'col' => 12, 'showWhen' => ['type', 'select']],
            ['name' => 'section', 'label' => 'Group under heading', 'type' => 'text', 'col' => 4, 'placeholder' => 'Optional, e.g. Next of kin'],
            ['name' => 'help_text', 'label' => 'Hint shown under the field', 'type' => 'text', 'col' => 8],
            ['name' => 'accept', 'label' => 'File types allowed', 'type' => 'text', 'col' => 5, 'default' => 'jpg,jpeg,png,pdf', 'help' => 'Comma separated.', 'showWhen' => ['type', 'file']],
            ['name' => 'max_kb', 'label' => 'Largest file (KB)', 'type' => 'number', 'col' => 4, 'default' => 2048, 'showWhen' => ['type', 'file']],
            ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'col' => 3, 'default' => 0],
            ['name' => 'is_required', 'label' => 'Required (when the person fills it in, or a record is created)', 'type' => 'switch', 'col' => 12],
            ['name' => 'self_service', 'label' => 'Person fills it in (online application / getting started)', 'type' => 'switch', 'col' => 12],
            ['name' => 'is_active', 'label' => 'Active (shown on the form)', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Form', fn ($r) => CustomField::TARGETS[$r->target] ?? $r->target],
            ['Field', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->label) . '</span>' . ($r->section ? '<div class="text-muted fs-12">' . e($r->section) . '</div>' : ''))],
            ['Type', fn ($r) => CustomField::TYPES[$r->type] ?? $r->type],
            ['Rules', fn ($r) => trim(($r->is_required ? 'Required' : 'Optional') . ($r->self_service ? ' · person fills in' : ''))],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Hidden</span>')],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $options = collect(preg_split('/\R/', (string) ($data['options_text'] ?? '')))->map(fn ($o) => trim($o))->filter()->unique()->values()->all();
        unset($data['options_text']);
        $data['options'] = ($data['type'] === 'select') ? $options : null;
        $data['accept'] = strtolower($data['accept'] ?? '') ?: 'jpg,jpeg,png,pdf';
        $data['max_kb'] = $data['max_kb'] ?? 2048;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        if (! $record) {
            $base = \App\Support\CustomFields::slug($data['label']);
            $key = $base;
            for ($i = 2; CustomField::where('target', $data['target'])->where('key', $key)->exists(); $i++) {
                $key = $base . '_' . $i;
            }
            $data['key'] = $key;
        }

        return $data;
    }

    protected function recordData(Model $row, array $fields): array
    {
        return array_merge(parent::recordData($row, $fields), ['options_text' => implode("\n", $row->options ?? [])]);
    }

    protected function blockDelete(Model $record): ?string
    {
        return null; // deleting removes the answers and uploaded files too; the confirmation says so
    }
}
