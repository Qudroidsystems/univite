<?php

namespace App\Http\Controllers;

use App\Data\Access\AccessScope;
use App\Models\Department;
use App\Models\LeaveApprovalStep;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Setting;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use App\Services\LeaveService;
use App\Services\OperationAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/** The leave office: every application, settings and approval routes, balances, adjustments and carry-over. */
class LeaveAdminController extends Controller
{
    public function __construct(private LeaveService $leave, private OperationAudit $audit, private ScopedAccess $access)
    {
        $this->middleware('scoped:View leave applications|Approve leave for any staff|Record staff resumption')->only('register');
        $this->middleware('permission:Set leave policy')->only('settings', 'updateSettings', 'updateRoute');
        $this->middleware('scoped:View leave applications|Change leave balances')->only('balances');
        $this->middleware('permission:Change leave balances')->only('adjust', 'carryOver');
    }

    /** Leave administrators see everyone; a head sees the staff of their department, faculty or unit. */
    private function scope(Request $request, string ...$global): AccessScope
    {
        $user = $request->user();

        return $user->canAny($global) ? AccessScope::everything() : $this->access->scope($user, 'View leave applications');
    }

    public function register(Request $request): View
    {
        $today = today();
        $scope = $this->scope($request, 'Approve leave for any staff', 'Record staff resumption');
        $base = fn () => $scope->staffUsers(LeaveRequest::query());
        $q = $scope->staffUsers(LeaveRequest::with('user.staffProfile.department', 'type', 'steps'))
            ->when($request->filled('status'), fn ($w) => $w->where('status', $request->status))
            ->when($request->filled('leave_type_id'), fn ($w) => $w->where('leave_type_id', $request->leave_type_id))
            ->when($request->filled('year'), fn ($w) => $w->where('year', $request->year))
            ->when($request->filled('department_id'), fn ($w) => $w->whereIn('user_id', StaffProfile::where('department_id', $request->department_id)->pluck('user_id')))
            ->when($request->query('phase') === 'away', fn ($w) => $w->where('status', 'approved')->whereNull('resumed_on')->whereDate('start_date', '<=', $today)->whereDate('expected_resumption', '>', $today))
            ->when($request->query('phase') === 'due', fn ($w) => $w->where('status', 'approved')->whereNull('resumed_on')->whereDate('expected_resumption', '<=', $today))
            ->when($request->query('phase') === 'late', fn ($w) => $w->where('late_days', '>', 0))
            ->when(trim((string) $request->q) !== '', fn ($w) => $w->where(fn ($x) => $x->where('reference', 'like', "%{$request->q}%")->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$request->q}%"))))
            ->latest('submitted_at');

        return view('leave.register', [
            'pagetitle' => 'Leave register', 'rows' => $q->paginate(40)->withQueryString(),
            'types' => LeaveType::orderBy('sort_order')->pluck('name', 'id'), 'departments' => $this->departments($scope),
            'stats' => [
                'pending' => $base()->where('status', 'pending')->count(),
                'away' => $base()->where('status', 'approved')->whereNull('resumed_on')->whereDate('start_date', '<=', $today)->whereDate('expected_resumption', '>', $today)->count(),
                'due' => $base()->where('status', 'approved')->whereNull('resumed_on')->whereDate('expected_resumption', '<=', $today)->count(),
                'overdue' => \App\Models\LeaveRequestStep::where('status', 'pending')->where('due_at', '<', now())->whereHas('request', fn ($r) => $scope->staffUsers($r))->count(),
            ],
        ]);
    }

    public function settings(Request $request): View
    {
        $types = LeaveType::orderBy('sort_order')->get();
        $type = $request->filled('type') ? $types->firstWhere('id', (int) $request->type) : null;
        $steps = LeaveApprovalStep::where('leave_type_id', $type?->id)->orderBy('sort_order')->get();

        return view('leave.settings', [
            'pagetitle' => 'Leave settings', 'values' => collect(LeaveService::SETTINGS)->map(fn ($s, $k) => LeaveService::setting($k)),
            'types' => $types, 'type' => $type, 'steps' => $steps, 'usesDefault' => $type && $steps->isEmpty(),
            'defaultSteps' => $type && $steps->isEmpty() ? LeaveApprovalStep::whereNull('leave_type_id')->orderBy('sort_order')->get() : collect(),
            'roles' => Role::orderBy('name')->pluck('name'),
            'customised' => LeaveApprovalStep::whereNotNull('leave_type_id')->distinct()->pluck('leave_type_id'),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'coverage_percent' => ['required', 'integer', 'between:1,100'], 'remind_before' => ['required', 'integer', 'between:0,60'],
            'remind_left' => ['nullable', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'], 'late_grace' => ['required', 'integer', 'between:0,30'],
            'weekend' => ['nullable', 'array'], 'weekend.*' => ['integer', 'between:1,7'],
        ], ['remind_left.regex' => 'List numbers of days separated by commas, for example 7,1.']);
        $before = collect(LeaveService::SETTINGS)->map(fn ($s, $k) => LeaveService::setting($k))->all();
        $d['weekend'] = implode(',', $d['weekend'] ?? []);
        $d['remind_left'] = preg_replace('/\s+/', '', (string) ($d['remind_left'] ?? ''));
        foreach (array_keys(LeaveService::SETTINGS) as $k) {
            Setting::put("leave.{$k}", (string) $d[$k]);
        }
        $this->audit->note('leave_policy', 'leave', $request->user(), 'settings changed', null, $before, $d);

        return back()->with('success', 'Leave settings saved.');
    }

    /** Replace a leave type's approval route (or the default route when no type is given). An empty route reverts a type to the default. */
    public function updateRoute(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'leave_type_id' => ['nullable', 'exists:leave_types,id'], 'steps' => ['nullable', 'array', 'max:8'],
            'steps.*.label' => ['nullable', 'string', 'max:100'], 'steps.*.approver' => ['nullable', Rule::in(['supervisor', 'hod', 'dean', 'role'])],
            'steps.*.role_name' => ['nullable', 'string', 'exists:roles,name'], 'steps.*.due_days' => ['nullable', 'integer', 'between:1,30'], 'steps.*.can_skip_if_missing' => ['nullable', 'boolean'],
        ]);
        $type = $d['leave_type_id'] ?? null;
        $rows = collect($d['steps'] ?? [])->filter(fn ($s) => ! empty($s['label']) && ! empty($s['approver']))->values();
        foreach ($rows as $s) {
            if ($s['approver'] === 'role' && empty($s['role_name'])) {
                return back()->withInput()->with('error', "Choose which role decides \"{$s['label']}\".");
            }
        }
        if (! $type && $rows->isEmpty()) {
            return back()->with('error', 'The default route needs at least one step.');
        }
        $before = LeaveApprovalStep::where('leave_type_id', $type)->orderBy('sort_order')->pluck('label')->all();
        DB::transaction(function () use ($type, $rows) {
            LeaveApprovalStep::where('leave_type_id', $type)->delete();
            foreach ($rows as $i => $s) {
                LeaveApprovalStep::create(['leave_type_id' => $type, 'sort_order' => $i + 1, 'label' => $s['label'], 'approver' => $s['approver'], 'role_name' => $s['approver'] === 'role' ? $s['role_name'] : null, 'due_days' => $s['due_days'] ?? 3, 'can_skip_if_missing' => ! empty($s['can_skip_if_missing'])]);
            }
        });
        $name = $type ? LeaveType::find($type)->name : 'Default';
        $this->audit->note('leave_policy', 'leave', $request->user(), 'approval route changed', null, ['route' => $name, 'steps' => $before], ['route' => $name, 'steps' => $rows->pluck('label')->all()]);

        return back()->with('success', $type && $rows->isEmpty() ? "{$name} now follows the default route." : "{$name} approval route saved. It applies to applications submitted from now on.");
    }

    private function departments(AccessScope $scope): \Illuminate\Support\Collection
    {
        return Department::when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->departmentIds))->orderBy('name')->pluck('name', 'id');
    }

    public function balances(Request $request): View
    {
        $year = (int) $request->query('year', now()->year);
        $scope = $this->scope($request, 'Change leave balances');
        $types = LeaveType::with('entitlements')->where('deducts_balance', true)->orderBy('sort_order')->get();
        $type = $types->firstWhere('id', (int) $request->query('type')) ?? $types->first();
        $staff = User::whereDoesntHave('student')->where('is_disabled', false)->with('staffProfile.department')
            ->when(! $scope->all, fn ($q) => $q->whereHas('staffProfile', fn ($p) => $scope->staffProfiles($p)))
            ->when($request->filled('department_id'), fn ($q) => $q->whereHas('staffProfile', fn ($p) => $p->where('department_id', $request->department_id)))
            ->when(trim((string) $request->q) !== '', fn ($q) => $q->where('name', 'like', "%{$request->q}%"))
            ->orderBy('name')->paginate(40)->withQueryString();

        return view('leave.balances', [
            'pagetitle' => 'Leave balances', 'year' => $year, 'types' => $types, 'type' => $type, 'staff' => $staff,
            'rows' => $type ? $staff->getCollection()->mapWithKeys(fn ($u) => [$u->id => $this->leave->balance($u, $type, $year)]) : collect(),
            'departments' => $this->departments($scope),
            'ledger' => $type ? $scope->staffUsers(LeaveLedgerEntry::with('user', 'creator'))->where(['leave_type_id' => $type->id, 'year' => $year])->latest()->limit(15)->get() : collect(),
        ]);
    }

    public function adjust(Request $request): RedirectResponse
    {
        $d = $request->validate(['user_id' => ['required', 'exists:users,id'], 'leave_type_id' => ['required', 'exists:leave_types,id'], 'year' => ['required', 'integer', 'between:2000,2100'], 'days' => ['required', 'numeric', 'between:-365,365', 'not_in:0'], 'note' => ['required', 'string', 'max:500']]);
        $user = User::findOrFail($d['user_id']);
        $type = LeaveType::findOrFail($d['leave_type_id']);
        $this->leave->adjust($user, $type, (int) $d['year'], (float) $d['days'], $d['note'], $request->user());

        return back()->with('success', sprintf('%s: %+g day(s) of %s for %d.', $user->name, $d['days'], $type->name, $d['year']));
    }

    public function carryOver(Request $request): RedirectResponse
    {
        $d = $request->validate(['from' => ['required', 'integer', 'between:2000,2100']]);
        $n = $this->leave->carryOver((int) $d['from'], $request->user());

        return back()->with('success', $n ? "Unused days carried from {$d['from']} into " . ($d['from'] + 1) . " for {$n} balance(s)." : 'Nothing to carry over (or it was already done).');
    }
}
