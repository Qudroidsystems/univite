<?php

namespace App\Http\Controllers\Academics;

use App\Models\ProgrammeCourse;
use App\Models\Semester;
use App\Models\SemesterType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class SemesterTypeController extends CrudController
{
    protected string $permission = 'semester types';
    protected string $title = 'Semester Types';
    protected string $singular = 'Semester type';
    protected string $icon = 'ri-calendar-event-line';
    protected string $routeBase = 'academics.semester-types';
    protected string $intro = 'The semesters your university runs each session, in order: First and Second, or Harmattan, Rain and Third. New sessions create these automatically, and curricula place courses against them. A non-teaching semester (such as a long vacation) is one students do not register courses for.';
    protected array $searchable = ['name'];

    protected function model(): string
    {
        return SemesterType::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderBy('sort_order');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name'        => ['required', 'string', 'max:100', Rule::unique('semester_types', 'name')->ignore($record?->id)],
            'sort_order'  => ['required', 'integer', 'between:1,9', Rule::unique('semester_types', 'sort_order')->ignore($record?->id)],
            'is_teaching' => ['nullable', 'boolean'],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Harmattan Semester'],
            ['name' => 'sort_order', 'label' => 'Order in the session', 'type' => 'number', 'required' => true, 'col' => 4, 'help' => '1 = first'],
            ['name' => 'is_teaching', 'label' => 'Teaching semester (students register courses)', 'type' => 'switch', 'col' => 12, 'default' => true],
            ['name' => 'is_active', 'label' => 'Active (created for new sessions)', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Order', fn ($r) => $r->sort_order],
            ['Semester', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>')],
            ['Teaching', fn ($r) => $r->is_teaching ? 'Yes' : 'No'],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }

    protected function blockDelete(Model $record): ?string
    {
        if (ProgrammeCourse::where('semester_order', $record->sort_order)->exists() || Semester::where('sort_order', $record->sort_order)->exists()) {
            return 'Curricula or sessions already use this semester. Mark it inactive instead.';
        }

        return null;
    }
}
