<?php

namespace App\Http\Controllers\Leave;

use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Support\Lookups;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

class LeaveEntitlementController extends PolicyCrud
{
    protected string $permission = 'leave policy';
    protected string $title = 'Leave Entitlement Rules';
    protected string $singular = 'Entitlement rule';
    protected string $icon = 'ri-scales-3-line';
    protected string $routeBase = 'leave.entitlements';
    protected string $intro = 'How many days a year each kind of staff gets. Blank criteria match everyone. When several rules match a person, the most specific one wins (category, then appointment, then grade, then years of service). With no matching rule, the leave type\'s default days apply.';
    protected int $perPage = 100;

    protected function model(): string
    {
        return LeaveEntitlement::class;
    }

    protected function filters(): array
    {
        return [['leave_type_id', 'Leave type', LeaveType::orderBy('sort_order')->pluck('name', 'id')->all()]];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'leave_type_id' => ['required', 'exists:leave_types,id'], 'category' => ['nullable', 'string', 'max:40'], 'appointment_type' => ['nullable', 'string', 'max:40'],
            'min_grade' => ['nullable', 'integer', 'between:1,20'], 'max_grade' => ['nullable', 'integer', 'between:1,20', 'gte:min_grade'],
            'min_years' => ['required', 'integer', 'between:0,60'], 'days' => ['required', 'integer', 'between:0,730'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'leave_type_id', 'lookup' => 'leave-types', 'label' => 'Leave type', 'type' => 'select', 'options' => LeaveType::orderBy('sort_order')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6],
            ['name' => 'days', 'label' => 'Days a year', 'type' => 'number', 'required' => true, 'col' => 6],
            ['name' => 'category', 'label' => 'Staff category', 'type' => 'select', 'options' => Lookups::options('staff_category'), 'blank' => 'Any category', 'col' => 6],
            ['name' => 'appointment_type', 'label' => 'Appointment', 'type' => 'select', 'options' => Lookups::options('appointment_type'), 'blank' => 'Any appointment', 'col' => 6],
            ['name' => 'min_grade', 'label' => 'From grade level', 'type' => 'number', 'col' => 4],
            ['name' => 'max_grade', 'label' => 'To grade level', 'type' => 'number', 'col' => 4],
            ['name' => 'min_years', 'label' => 'Years of service, at least', 'type' => 'number', 'required' => true, 'col' => 4, 'default' => 0],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Leave type', fn ($r) => new HtmlString('<strong>' . e($r->type?->name) . '</strong>')],
            ['Who', fn ($r) => collect([$r->category ? Lookups::label('staff_category', $r->category) : 'Any category', $r->appointment_type ? Lookups::label('appointment_type', $r->appointment_type) : null,
                $r->min_grade || $r->max_grade ? 'grade ' . ($r->min_grade ?? '1') . '–' . ($r->max_grade ?? 'up') : null, $r->min_years ? $r->min_years . '+ years of service' : null])->filter()->implode(' · ')],
            ['Days', fn ($r) => $r->days],
        ];
    }
}
