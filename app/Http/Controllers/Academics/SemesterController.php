<?php

namespace App\Http\Controllers\Academics;

use App\Models\AcademicSession;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class SemesterController extends CrudController
{
    protected string $permission = 'semester';
    protected string $title = 'Semesters';
    protected string $singular = 'Semester';
    protected string $icon = 'ri-calendar-2-line';
    protected string $routeBase = 'academics.semesters';
    protected string $intro = 'Each session is split into semesters (First, Second ...). Setting a semester as current also makes its session current.';
    protected array $searchable = ['name'];

    public function __construct()
    {
        parent::__construct();
        $this->middleware('permission:Set current semester')->only('makeCurrent');
    }

    protected function model(): string
    {
        return Semester::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('session')->reorder()->orderByDesc('academic_session_id')->orderBy('sort_order');
    }

    protected function sessionOptions(): array
    {
        return AcademicSession::orderByDesc('name')->pluck('name', 'id')->all();
    }

    protected function filters(): array
    {
        return [['academic_session_id', 'Session', $this->sessionOptions()]];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $sessionId = $request->input('academic_session_id');

        return [
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'name'         => ['required', 'string', 'max:50', Rule::unique('semesters', 'name')->where('academic_session_id', $sessionId)->ignore($record?->id)],
            'sort_order'   => ['required', 'integer', 'min:1', 'max:9', Rule::unique('semesters', 'sort_order')->where('academic_session_id', $sessionId)->ignore($record?->id)],
            'start_date'   => ['nullable', 'date'],
            'end_date'     => ['nullable', 'date', 'after:start_date'],
            'make_current' => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'academic_session_id', 'label' => 'Session', 'type' => 'select', 'options' => $this->sessionOptions(), 'required' => true, 'col' => 12],
            ['name' => 'name', 'label' => 'Semester name', 'type' => 'text', 'required' => true, 'col' => 8, 'placeholder' => 'First Semester'],
            ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'required' => true, 'col' => 4, 'help' => '1 = first, 2 = second', 'default' => 1],
            ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'col' => 6],
            ['name' => 'end_date', 'label' => 'End date', 'type' => 'date', 'col' => 6],
            ['name' => 'make_current', 'label' => 'Make this the current semester', 'type' => 'switch', 'col' => 12, 'transient' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Session', fn ($r) => $r->session->name],
            ['Semester', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>' . ($r->is_current ? ' <span class="badge bg-success ms-1">Current</span>' : ''))],
            ['Order', fn ($r) => $r->sort_order],
            ['Start', fn ($r) => $r->start_date?->format('d M Y') ?? '—'],
            ['End', fn ($r) => $r->end_date?->format('d M Y') ?? '—'],
        ];
    }

    protected function rowActions(): array
    {
        return [
            ['Make current', 'academics.semesters.make-current', 'Set current semester', 'ri-checkbox-circle-line', fn ($r) => ! $r->is_current],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        unset($data['make_current']);

        return $data;
    }

    protected function saved(Model $record, Request $request, bool $created): void
    {
        if ($request->boolean('make_current')) {
            $record->makeCurrent();
        }
    }

    public function makeCurrent($semester): RedirectResponse
    {
        Semester::with('session')->findOrFail($semester)->makeCurrent();

        return back()->with('success', 'Current semester updated.');
    }
}
