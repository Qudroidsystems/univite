<?php

namespace App\Http\Controllers\Leave;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class HolidayController extends PolicyCrud
{
    protected string $permission = 'holidays';
    protected array $viewers = ['Create timetables'];
    protected string $title = 'Holiday Calendar';
    protected string $singular = 'Holiday';
    protected string $icon = 'ri-calendar-event-line';
    protected string $routeBase = 'leave.holidays';
    protected string $intro = 'Public holidays and closure days. Leave does not count them as working days, and examination timetables do not use them. Mark a holiday as yearly when it falls on the same date every year.';
    protected array $searchable = ['name'];
    protected int $perPage = 100;

    protected function model(): string
    {
        return Holiday::class;
    }

    protected function query(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return parent::query($request)->reorder()->orderByDesc('date');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return ['date' => ['required', 'date'], 'name' => ['required', 'string', 'max:100'], 'recurring' => ['nullable', 'boolean']];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'col' => 4],
            ['name' => 'name', 'label' => 'Name', 'required' => true, 'col' => 8],
            ['name' => 'recurring', 'label' => 'Same date every year', 'type' => 'switch', 'col' => 12],
        ];
    }

    protected function columns(): array
    {
        return [['Date', fn ($r) => $r->date->format('D j M Y')], ['Holiday', fn ($r) => $r->name], ['Every year', fn ($r) => $r->recurring ? 'Yes' : 'No']];
    }
}
