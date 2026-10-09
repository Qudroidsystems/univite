<?php

namespace App\Http\Controllers\Academics;

use App\Models\DegreeClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class DegreeClassController extends CrudController
{
    protected string $permission = 'degree classes';
    protected string $title = 'Classes of Degree';
    protected string $singular = 'Class';
    protected string $icon = 'ri-medal-line';
    protected string $routeBase = 'academics.degree-classes';
    protected string $intro = 'The class a graduating student earns from their CGPA: the highest class whose minimum CGPA the student reaches. Name them as your university does (First Class, Distinction, Merit ...).';

    protected function model(): string
    {
        return DegreeClass::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderByDesc('min_cgpa');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name'     => ['required', 'string', 'max:80', \Illuminate\Validation\Rule::unique('degree_classes', 'name')->ignore($record?->id)],
            'min_cgpa' => ['required', 'numeric', 'between:0,10', \Illuminate\Validation\Rule::unique('degree_classes', 'min_cgpa')->ignore($record?->id)],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Class name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'Second Class Upper'],
            ['name' => 'min_cgpa', 'label' => 'Minimum CGPA', 'type' => 'number', 'step' => 0.01, 'required' => true, 'col' => 4],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Class', fn ($r) => $r->name],
            ['Minimum CGPA', fn ($r) => number_format($r->min_cgpa, 2)],
        ];
    }
}
