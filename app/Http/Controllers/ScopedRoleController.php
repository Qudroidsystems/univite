<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\ScopedRole;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Services\Access\HeadRoles;
use App\Services\Access\ScopedAccess;
use App\Services\OperationAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/** Roles held for one faculty, department or unit: the ones heads get automatically, and any given by hand. */
class ScopedRoleController extends Controller
{
    public function __construct(private HeadRoles $heads, private ScopedAccess $access, private OperationAudit $audit)
    {
        $this->middleware('permission:View scoped roles|Assign scoped roles')->only('index');
        $this->middleware('permission:Assign scoped roles')->only('store', 'destroy', 'settings');
    }

    private function scopes(): array
    {
        return [
            'Faculties' => Faculty::orderBy('name')->get()->mapWithKeys(fn ($f) => ["faculty:{$f->id}" => $f->name])->all(),
            'Departments' => Department::orderBy('name')->get()->mapWithKeys(fn ($d) => ["department:{$d->id}" => $d->name])->all(),
            'Units' => Unit::orderBy('name')->get()->mapWithKeys(fn ($u) => ["unit:{$u->id}" => $u->name])->all(),
        ];
    }

    public function index(Request $request): View
    {
        $rows = ScopedRole::with('user', 'role')->when($request->filled('source'), fn ($q) => $q->where('source', $request->query('source')))
            ->orderBy('scope_type')->orderBy('scope_id')->get();

        return view('access.scoped-roles', [
            'pagetitle' => 'Scoped roles', 'rows' => $rows, 'scopes' => $this->scopes(),
            'roles' => Role::where('name', '!=', 'Super Admin')->orderBy('name')->pluck('name', 'id'),
            'users' => User::whereDoesntHave('student')->where('is_disabled', false)->orderBy('name')->pluck('name', 'id'),
            'headRoles' => collect(HeadRoles::HEADS)->mapWithKeys(fn ($h, $type) => [$type => $this->heads->roleName($type)]),
            'scopable' => ScopedAccess::SCOPABLE,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(['user_id' => ['required', 'exists:users,id'], 'role_id' => ['required', 'exists:roles,id'], 'scope' => ['required', 'regex:/^(faculty|department|unit):\d+$/']]);
        [$type, $id] = explode(':', $d['scope']);
        $row = ScopedRole::firstOrCreate(['user_id' => $d['user_id'], 'role_id' => $d['role_id'], 'scope_type' => $type, 'scope_id' => (int) $id], ['source' => 'manual', 'created_by' => $request->user()->id]);
        $this->access->forget();
        $this->audit->note('scoped_role', 'access', $request->user(), 'scoped_role_given', $row, null, ['user' => $row->user->name, 'role' => $row->role->name, 'for' => $row->scopeName()]);

        return back()->with('success', "{$row->user->name} now holds {$row->role->name} for {$row->scopeName()}.");
    }

    public function destroy(Request $request, ScopedRole $scopedRole): RedirectResponse
    {
        if ($scopedRole->source === 'head') {
            return back()->with('error', 'This role comes with heading the ' . $scopedRole->scope_type . '. Change the head on the ' . $scopedRole->scope_type . ' instead.');
        }
        $this->audit->note('scoped_role', 'access', $request->user(), 'scoped_role_removed', null, ['user' => $scopedRole->user->name, 'role' => $scopedRole->role->name, 'for' => $scopedRole->scopeName()], null);
        $scopedRole->delete();
        $this->access->forget();

        return back()->with('success', 'Scoped role removed.');
    }

    /** Which role each kind of head gets; saving brings every head up to date. */
    public function settings(Request $request): RedirectResponse
    {
        $names = Role::pluck('name')->all();
        $d = $request->validate(collect(HeadRoles::HEADS)->mapWithKeys(fn ($h, $type) => [$type => ['nullable', Rule::in($names)]])->all());
        foreach (HeadRoles::HEADS as $type => [, , $key]) {
            Setting::put($key, $d[$type] ?? '');
        }
        $n = $this->heads->syncAll();

        return back()->with('success', "Head roles saved. {$n} head(s) hold their role.");
    }
}
