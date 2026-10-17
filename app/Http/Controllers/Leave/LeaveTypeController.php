<?php

namespace App\Http\Controllers\Leave;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class LeaveTypeController extends PolicyCrud
{
    protected string $permission = 'leave policy';
    protected string $title = 'Leave Types';
    protected string $singular = 'Leave type';
    protected string $icon = 'ri-suitcase-line';
    protected string $routeBase = 'leave.types';
    protected string $intro = 'The kinds of leave staff can apply for. Days here are the default entitlement; entitlement rules can give more or fewer days by category, grade, appointment and years of service. Confidential types (such as sick leave) keep their documents away from approvers who are not allowed to see them.';
    protected array $searchable = ['name', 'code'];
    protected int $perPage = 50;

    protected function model(): string
    {
        return LeaveType::class;
    }

    protected function query(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return parent::query($request)->reorder()->orderBy('sort_order')->orderBy('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('leave_types', 'code')->ignore($record?->id)],
            'name' => ['required', 'string', 'max:100', Rule::unique('leave_types', 'name')->ignore($record?->id)], 'description' => ['nullable', 'string', 'max:1000'],
            'default_days' => ['required', 'integer', 'between:0,730'], 'working_days' => ['nullable', 'boolean'], 'paid' => ['nullable', 'boolean'],
            'needs_document' => ['nullable', 'boolean'], 'document_label' => ['nullable', 'string', 'max:150'], 'confidential' => ['nullable', 'boolean'],
            'gender' => ['nullable', Rule::in(['male', 'female'])], 'min_service_months' => ['required', 'integer', 'between:0,600'],
            'max_days_per_request' => ['nullable', 'integer', 'between:1,730'], 'notice_days' => ['required', 'integer', 'between:0,365'],
            'max_carryover' => ['required', 'integer', 'between:0,365'], 'needs_acting_officer' => ['nullable', 'boolean'], 'deducts_balance' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,999'], 'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'col' => 6],
            ['name' => 'code', 'label' => 'Code', 'required' => true, 'col' => 3, 'help' => 'Short, no spaces.'],
            ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'required' => true, 'col' => 3, 'default' => 10],
            ['name' => 'default_days', 'label' => 'Days a year', 'type' => 'number', 'required' => true, 'col' => 3],
            ['name' => 'working_days', 'label' => 'Count working days only', 'type' => 'switch', 'col' => 3, 'default' => true, 'help' => 'Off: calendar days (maternity, study).'],
            ['name' => 'deducts_balance', 'label' => 'Uses up a yearly balance', 'type' => 'switch', 'col' => 3, 'default' => true],
            ['name' => 'paid', 'label' => 'Paid', 'type' => 'switch', 'col' => 3, 'default' => true],
            ['name' => 'max_days_per_request', 'label' => 'Most days at once', 'type' => 'number', 'col' => 3],
            ['name' => 'notice_days', 'label' => 'Apply this many days ahead', 'type' => 'number', 'required' => true, 'col' => 3, 'default' => 0],
            ['name' => 'min_service_months', 'label' => 'Months of service needed', 'type' => 'number', 'required' => true, 'col' => 3, 'default' => 0],
            ['name' => 'max_carryover', 'label' => 'Days that can carry over', 'type' => 'number', 'required' => true, 'col' => 3, 'default' => 0],
            ['name' => 'gender', 'label' => 'Only for', 'type' => 'select', 'options' => ['female' => 'Female staff', 'male' => 'Male staff'], 'blank' => 'Everyone', 'col' => 4],
            ['name' => 'needs_document', 'label' => 'Needs a supporting document', 'type' => 'switch', 'col' => 4],
            ['name' => 'document_label', 'label' => 'Which document', 'col' => 4, 'placeholder' => 'Medical certificate'],
            ['name' => 'confidential', 'label' => 'Documents are confidential (medical)', 'type' => 'switch', 'col' => 6],
            ['name' => 'needs_acting_officer', 'label' => 'An acting officer must be named', 'type' => 'switch', 'col' => 6],
            ['name' => 'description', 'label' => 'Description shown to staff', 'type' => 'textarea', 'col' => 12],
            ['name' => 'is_active', 'label' => 'Open for applications', 'type' => 'switch', 'col' => 6, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Leave type', fn ($r) => new HtmlString('<strong>' . e($r->name) . '</strong><div class="text-muted fs-12">' . e($r->code) . ($r->gender ? ' · ' . e($r->gender) . ' staff only' : '') . '</div>')],
            ['Days', fn ($r) => $r->default_days . ($r->working_days ? ' working' : ' calendar')],
            ['Rules', fn ($r) => collect([$r->needs_document ? 'document: ' . ($r->document_label ?: 'required') : null, $r->confidential ? 'confidential' : null, $r->notice_days ? $r->notice_days . ' days notice' : null, $r->min_service_months ? $r->min_service_months . ' months service' : null, $r->max_carryover ? 'carry over ' . $r->max_carryover : null, $r->needs_acting_officer ? 'acting officer' : null])->filter()->implode(', ') ?: '—'],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Open</span>' : '<span class="badge bg-secondary-subtle text-secondary">Closed</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        return LeaveRequest::where('leave_type_id', $record->id)->exists() ? 'Applications use this leave type. Close it for applications instead.' : null;
    }
}
