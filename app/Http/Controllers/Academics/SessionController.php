<?php

namespace App\Http\Controllers\Academics;

use App\Models\AcademicSession;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use App\Services\SessionLifecycle;
use Illuminate\Validation\Rule;
use RuntimeException;

class SessionController extends CrudController
{
    protected string $permission = 'academic sessions';
    protected string $title = 'Academic Sessions';
    protected string $singular = 'Session';
    protected string $icon = 'ri-calendar-event-line';
    protected string $routeBase = 'academics.sessions';
    protected string $intro = 'An academic session is one school year, e.g. 2025/2026. Only one session is current at a time.';
    protected array $searchable = ['name'];
    protected array $copyCategories = ['registration', 'windows', 'bills', 'assignments', 'assessments'];
    protected array $snapshotSections = ['registration', 'windows', 'bills', 'assignments', 'assessments'];

    public function __construct(private SessionLifecycle $lifecycle)
    {
        parent::__construct();
        $this->middleware('permission:Set the current academic session')->only('makeCurrent');
    }

    protected function model(): string
    {
        return AcademicSession::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->withCount('semesters')->reorder()->orderByDesc('name');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'name'             => ['required', 'string', 'max:20', 'regex:/^\d{4}\/\d{4}$/', Rule::unique('academic_sessions', 'name')->ignore($record?->id)],
            'start_date'       => ['nullable', 'date'],
            'end_date'         => ['nullable', 'date', 'after:start_date'],
            'make_current'     => ['nullable', 'boolean'],
            'create_semesters' => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Session', 'type' => 'text', 'required' => true, 'col' => 12, 'placeholder' => '2025/2026', 'help' => 'Format: YYYY/YYYY'],
            ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'col' => 6],
            ['name' => 'end_date', 'label' => 'End date', 'type' => 'date', 'col' => 6],
            ['name' => 'make_current', 'label' => 'Make this the current session', 'type' => 'switch', 'col' => 12, 'transient' => true],
            ['name' => 'create_semesters', 'label' => 'Also create this session\'s semesters (see Semester Types)', 'type' => 'switch', 'col' => 12, 'transient' => true, 'only' => 'create', 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Session', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->name) . '</span>' . ($r->is_current ? ' <span class="badge bg-success ms-1">Current</span>' : ''))],
            ['Status', fn ($r) => new HtmlString('<a href="' . e(route('sessions.lifecycle', $r->id)) . '" class="badge bg-' . (SessionLifecycle::BADGES[$r->status] ?? 'secondary') . '" title="Open the status page">' . e(SessionLifecycle::STATUSES[$r->status] ?? $r->status) . '</a>')],
            ['Start', fn ($r) => $r->start_date?->format('d M Y') ?? '—'],
            ['End', fn ($r) => $r->end_date?->format('d M Y') ?? '—'],
            ['Semesters', fn ($r) => $r->semesters_count],
        ];
    }

    protected function rowActions(): array
    {
        return [
            ['Make current', 'academics.sessions.make-current', 'Set the current academic session', 'ri-checkbox-circle-line', fn ($r) => ! $r->is_current],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        unset($data['make_current'], $data['create_semesters']);

        return $data;
    }

    protected function saved(Model $record, Request $request, bool $created): void
    {
        if ($created && $request->boolean('create_semesters')) {
            foreach (\App\Models\SemesterType::options() as $order => $name) {
                Semester::firstOrCreate(
                    ['academic_session_id' => $record->id, 'name' => $name],
                    ['sort_order' => $order]
                );
            }
        }

        if ($request->boolean('make_current')) {
            try {
                $this->lifecycle->makeCurrent($record, $request->user());
            } catch (RuntimeException $e) {
                session()->flash('error', $record->name . ' was saved but could not be made current: ' . $e->getMessage());
            }
        }
    }

    public function makeCurrent($session): RedirectResponse
    {
        try {
            $this->lifecycle->makeCurrent(AcademicSession::findOrFail($session), request()->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Current session updated.');
    }
}
