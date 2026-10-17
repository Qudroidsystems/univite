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
    /** Which of create / edit / delete this page offers (each also needs its permission). */
    protected array $actions = ['create', 'edit', 'delete'];
    protected int $perPage = 20;
    /** Optional partial rendered above the table. */
    protected ?string $extraView = null;
    /** Set to applicant | student | staff to append the admin-defined custom fields to this page's form. */
    protected ?string $customTarget = null;

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
        $this->middleware($this->viewMiddleware())->only('index');
        in_array('create', $this->actions) && $this->middleware($this->actionMiddleware('Create'))->only('store');
        in_array('edit', $this->actions) && $this->middleware($this->actionMiddleware('Edit'))->only('update');
        in_array('delete', $this->actions) && $this->middleware($this->actionMiddleware('Delete'))->only('destroy');
    }

    /** Middleware guarding create (Create), update (Edit) and delete (Delete). A page heads may use for their own department overrides it. */
    protected function actionMiddleware(string $verb): string
    {
        return "permission:{$verb} {$this->permission}";
    }

    /** Middleware guarding the list page. A page that heads may see for their own department returns a scoped middleware instead. */
    protected function viewMiddleware(): string
    {
        return 'permission:View ' . $this->permission;
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

    /** Return a reason to refuse deleting this record, or null to allow it. */
    protected function blockDelete(Model $record): ?string
    {
        return null;
    }

    /** Runs after create/update succeeded. */
    protected function saved(Model $record, Request $request, bool $created): void
    {
    }

    /** The page's own fields plus any custom fields an administrator has added for it. */
    protected function allFields(): array
    {
        return array_merge($this->fields(), \App\Support\CustomFields::formFields($this->customTarget));
    }

    /** Validate with the page rules plus custom-field rules; returns only the page's own data. */
    private function validated(Request $request, ?Model $record): array
    {
        $custom = \App\Support\CustomFields::rules($this->customTarget, $record, enforceRequired: $record === null);
        $data = $request->validate(
            $this->rules($request, $record) + $custom,
            [],
            \App\Support\CustomFields::attributeNames($this->customTarget)
        );

        return array_diff_key($data, $custom);
    }

    public function index(Request $request): View
    {
        $fields = $this->allFields();

        return view('academics.crud', array_merge([
            'pagetitle'  => $this->title,
            'title'      => $this->title,
            'singular'   => $this->singular,
            'icon'       => $this->icon,
            'intro'      => $this->intro,
            'permission' => $this->permission,
            'actions'    => $this->actions,
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
        $data = $this->validated($request, null);
        $data = $this->prepare($this->normalise($data, $request), $request, null);

        $modelClass = $this->model();
        $record = $modelClass::create($data);
        \App\Support\CustomFields::save($this->customTarget, $record, $request);
        $this->saved($record, $request, true);

        return back()->with('success', "{$this->singular} created successfully.");
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $modelClass = $this->model();
        $record = $modelClass::findOrFail($id);

        $data = $this->validated($request, $record);
        $data = $this->prepare($this->normalise($data, $request), $request, $record);

        $record->update($data);
        \App\Support\CustomFields::save($this->customTarget, $record, $request);
        $this->saved($record, $request, false);

        return back()->with('success', "{$this->singular} updated successfully.");
    }

    public function destroy($id): RedirectResponse
    {
        $modelClass = $this->model();
        $record = $modelClass::findOrFail($id);

        if ($reason = $this->blockDelete($record)) {
            return back()->with('error', $reason);
        }

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

    protected function recordData(Model $row, array $fields): array
    {
        $out = ['id' => $row->getKey()] + \App\Support\CustomFields::recordData($this->customTarget, $row);
        foreach ($fields as $field) {
            if (! empty($field['transient']) || ! empty($field['custom'])) {
                continue;
            }
            $name = $field['name'];
            $value = $row->{$name} ?? null;
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(($field['type'] ?? '') === 'datetime-local' ? 'Y-m-d\TH:i' : 'Y-m-d');
            }
            $out[$name] = $value;
        }

        return $out;
    }
}
