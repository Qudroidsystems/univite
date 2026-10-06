<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shared list + modal create/edit/delete behaviour for the simple setup pages
 * (sessions, semesters, faculties, departments, programmes, courses, bill items, bills).
 *
 * A concrete controller describes its page (title, fields, columns, rules); the
 * `academics.crud` view renders it.
 */
abstract class CrudController extends Controller
{
    /** Permission slug: "View {slug}", "Create {slug}", "Update {slug}", "Delete {slug}". */
    protected string $permission;
    protected string $title;        // "Academic Sessions"
    protected string $singular;     // "Session"
    protected string $icon = 'ri-settings-3-line';
    protected string $routeBase;    // "academics.sessions"
    protected string $intro = '';
    protected array $searchable = [];
    protected int $perPage = 20;
    /** Optional partial rendered above the table. */
    protected ?string $extraView = null;

    abstract protected function model(): string;

    /** @return array<string, mixed> validation rules. $record is set on update. */
    abstract protected function rules(Request $request, ?Model $record): array;

    /**
     * Form fields: name, label, type (text|number|date|select|textarea|switch),
     * options ([value => label]), required, col, help, placeholder, default, blank, transient, only.
     */
    abstract protected function fields(): array;

    /** Table columns: [label, fn(Model): mixed]. Return an HtmlString for raw HTML. */
    abstract protected function columns(): array;

    public function __construct()
    {
        $this->middleware('permission:View ' . $this->permission)->only('index');
        $this->middleware('permission:Create ' . $this->permission)->only('store');
        $this->middleware('permission:Edit ' . $this->permission)->only('update');
        $this->middleware('permission:Delete ' . $this->permission)->only('destroy');
    }

    /** Extra GET filters: [column, label, options[value => label]] */
    protected function filters(): array
    {
        return [];
    }

    /** Extra per-row POST buttons: [label, route name, permission, icon, visible(fn(Model): bool)]. */
    protected function rowActions(): array
    {
        return [];
    }

    protected function query(Request $request): Builder
    {
        $modelClass = $this->model();
        $query = $modelClass::query()->latest('id');

        if (($q = trim((string) $request->query('q'))) !== '' && $this->searchable) {
            $query->where(function ($w) use ($q) {
                foreach ($this->searchable as $col) {
                    $w->orWhere($col, 'like', "%{$q}%");
                }
            });
        }

        foreach ($this->filters() as [$name]) {
            if ($request->filled($name)) {
                $query->where($name, $request->query($name));
            }
        }

        return $query;
    }

    /** Values merged into the view (e.g. summaries). */
    protected function viewData(Request $request): array
    {
        return [];
    }

    /** Adjust validated data before it is saved. $record is null on create. */
    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        return $data;
    }

    /** Runs after create/update succeeded. */
    protected function saved(Model $record, Request $request, bool $created): void
    {
    }

    public function index(Request $request): View
    {
        $fields = $this->fields();

        return view('academics.crud', array_merge([
            'pagetitle'  => $this->title,
            'title'      => $this->title,
            'singular'   => $this->singular,
            'icon'       => $this->icon,
            'intro'      => $this->intro,
            'permission' => $this->permission,
            'routeBase'  => $this->routeBase,
            'fields'     => $fields,
            'columns'    => $this->columns(),
            'filters'    => $this->filters(),
            'rowActions' => $this->rowActions(),
            'rows'       => $this->query($request)->paginate($this->perPage)->withQueryString(),
            'canSearch'  => ! empty($this->searchable),
            'extraView'  => $this->extraView,
            'recordData' => fn (Model $row) => $this->recordData($row, $fields),
        ], $this->viewData($request)));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules($request, null));
        $data = $this->prepare($this->normalise($data, $request), $request, null);

        $modelClass = $this->model();
        $record = $modelClass::create($data);
        $this->saved($record, $request, true);

        return back()->with('success', "{$this->singular} created successfully.");
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $modelClass = $this->model();
        $record = $modelClass::findOrFail($id);

        $data = $request->validate($this->rules($request, $record));
        $data = $this->prepare($this->normalise($data, $request), $request, $record);

        $record->update($data);
        $this->saved($record, $request, false);

        return back()->with('success', "{$this->singular} updated successfully.");
    }

    public function destroy($id): RedirectResponse
    {
        $modelClass = $this->model();
        $record = $modelClass::findOrFail($id);

        try {
            $record->delete();
        } catch (QueryException $e) {
            return back()->with('error', "This {$this->singular} is in use and cannot be deleted. Deactivate it instead, or remove what depends on it first.");
        }

        return back()->with('success', "{$this->singular} deleted.");
    }

    /** Switch fields are checkboxes (absent = false); blank strings become null. */
    private function normalise(array $data, Request $request): array
    {
        foreach ($this->fields() as $field) {
            $name = $field['name'];
            if (($field['type'] ?? 'text') === 'switch') {
                $data[$name] = $request->boolean($name);
            } elseif (array_key_exists($name, $data) && $data[$name] === '') {
                $data[$name] = null;
            }
        }

        return $data;
    }

    private function recordData(Model $row, array $fields): array
    {
        $out = ['id' => $row->getKey()];
        foreach ($fields as $field) {
            if (! empty($field['transient'])) {
                continue;
            }
            $name = $field['name'];
            $value = $row->{$name} ?? null;
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            $out[$name] = $value;
        }

        return $out;
    }
}
