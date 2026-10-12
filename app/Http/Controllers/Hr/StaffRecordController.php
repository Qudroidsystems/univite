<?php

namespace App\Http\Controllers\Hr;

use App\Data\Access\AccessScope;
use App\Http\Controllers\Academics\CrudController;
use App\Models\Department;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\StaffPayItem;
use App\Models\StaffProfile;
use App\Models\Unit;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use App\Services\Hr\RetirementRules;
use App\Services\OperationAudit;
use App\Support\Lookups;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Staff records: the list (with add and edit of the employment details), each person's record page with their pay,
 * pension and tax details and their own earnings and deductions, and the list of staff due to retire.
 * Heads see the staff of their own department, faculty or unit, without pay details.
 */
class StaffRecordController extends CrudController
{
    protected string $permission = 'staff records';
    protected ?string $customTarget = 'staff';
    protected string $title = 'Staff Records';
    protected string $singular = 'Staff record';
    protected string $icon = 'ri-id-card-line';
    protected string $routeBase = 'staff';
    protected string $intro = 'Everyone employed by the university: where they work, designation, category, grade, appointment and service, and status. Open a record for pay, bank, pension and tax details, personal earnings and deductions, and payslips.';
    protected int $perPage = 50;
    protected array $actions = ['create', 'edit'];

    public function __construct(private ScopedAccess $access, private RetirementRules $retirement, private OperationAudit $audit)
    {
        $this->middleware('scoped:View staff records|Edit staff profiles')->only('index', 'show', 'retirements');
        $this->middleware('permission:Edit staff profiles')->only('store', 'update');
        $this->middleware('permission:Edit staff pay details')->only('updatePay');
        $this->middleware('permission:Change staff pay')->only('storeItem', 'updateItem');
    }

    protected function model(): string
    {
        return StaffProfile::class;
    }

    /** Those who edit staff records see everyone; heads see only their own department, faculty or unit. */
    private function scope(Request $request): AccessScope
    {
        return $request->user()->can('Edit staff profiles') ? AccessScope::everything() : $this->access->scope($request->user(), 'View staff records');
    }

    protected function query(Request $request): Builder
    {
        $q = parent::query($request)->with('user', 'department', 'unit', 'supervisor', 'salaryStructure')->reorder()
            ->orderBy(User::select('name')->whereColumn('users.id', 'staff_profiles.user_id'));
        $this->scope($request)->staffProfiles($q);
        if (($s = trim((string) $request->query('q'))) !== '') {
            $q->where(fn ($w) => $w->where('staff_number', 'like', "%{$s}%")->orWhere('designation', 'like', "%{$s}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")));
        }

        return $q;
    }

    protected function filters(): array
    {
        return [
            ['department_id', 'Department', Department::orderBy('name')->pluck('name', 'id')->all()],
            ['unit_id', 'Unit', Unit::orderBy('name')->pluck('name', 'id')->all()],
            ['category', 'Category', Lookups::options('staff_category')],
            ['employment_status', 'Status', Lookups::options('employment_status')],
        ];
    }

    private function staffOptions(): array
    {
        return User::whereDoesntHave('student')->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'user_id' => $record ? ['nullable'] : ['required', 'exists:users,id', Rule::unique('staff_profiles', 'user_id')],
            'title' => ['nullable', 'string', 'max:20'],
            'staff_number' => ['nullable', 'string', 'max:40', Rule::unique('staff_profiles', 'staff_number')->ignore($record?->id)],
            'department_id' => ['nullable', 'exists:departments,id'], 'unit_id' => ['nullable', 'exists:units,id'], 'designation' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:40'], 'appointment_type' => ['nullable', 'string', 'max:40'], 'grade_level' => ['nullable', 'integer', 'between:1,20'],
            'first_appointment_date' => ['nullable', 'date', 'before_or_equal:today'], 'confirmation_date' => ['nullable', 'date'],
            'supervisor_id' => ['nullable', 'exists:users,id'], 'critical_role' => ['nullable', 'boolean'], 'professorial' => ['nullable', 'boolean'],
            'employment_status' => ['required', 'string', 'max:30'], 'exit_date' => ['nullable', 'date'], 'exit_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'user_id', 'label' => 'Member of staff', 'type' => 'select', 'options' => $this->staffOptions(), 'required' => true, 'col' => 6, 'only' => 'create', 'help' => 'Their sign-in account. Add it under Users first.'],
            ['name' => 'title', 'label' => 'Title', 'type' => 'select', 'options' => Lookups::options('title'), 'blank' => 'None', 'col' => 2],
            ['name' => 'staff_number', 'label' => 'Staff number', 'col' => 4],
            ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'options' => Department::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'None', 'col' => 6],
            ['name' => 'unit_id', 'label' => 'Unit / office', 'type' => 'select', 'options' => Unit::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'None', 'col' => 6, 'help' => 'For staff of the Bursary, Registry, Works …'],
            ['name' => 'designation', 'label' => 'Designation', 'col' => 6, 'placeholder' => 'Senior Lecturer'],
            ['name' => 'grade_level', 'label' => 'Grade level', 'type' => 'number', 'col' => 3],
            ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'options' => Lookups::options('staff_category'), 'blank' => 'Not set', 'col' => 3],
            ['name' => 'appointment_type', 'label' => 'Appointment', 'type' => 'select', 'options' => Lookups::options('appointment_type'), 'blank' => 'Not set', 'col' => 4],
            ['name' => 'first_appointment_date', 'label' => 'First appointment', 'type' => 'date', 'col' => 4],
            ['name' => 'confirmation_date', 'label' => 'Confirmed', 'type' => 'date', 'col' => 4],
            ['name' => 'supervisor_id', 'label' => 'Supervisor', 'type' => 'select', 'options' => $this->staffOptions(), 'blank' => 'None', 'col' => 6],
            ['name' => 'employment_status', 'label' => 'Status', 'type' => 'select', 'options' => Lookups::options('employment_status'), 'default' => 'active', 'required' => true, 'col' => 6],
            ['name' => 'exit_date', 'label' => 'Left on', 'type' => 'date', 'col' => 4, 'help' => 'Last day in service, if they have left.'],
            ['name' => 'exit_reason', 'label' => 'Reason for leaving', 'col' => 8],
            ['name' => 'critical_role', 'label' => 'Must be covered when away', 'type' => 'switch', 'col' => 6],
            ['name' => 'professorial', 'label' => 'Professorial cadre (later retirement age)', 'type' => 'switch', 'col' => 6],
        ];
    }

    protected function viewData(Request $request): array
    {
        $can = $request->user()->can('Edit staff profiles');

        return ['canSearch' => true, 'abilities' => ['create' => $can, 'edit' => $can, 'delete' => false]];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        if ($record) {
            unset($data['user_id']); // the person a record belongs to never changes
        }

        return $data;
    }

    protected function columns(): array
    {
        return [
            ['Staff', fn ($r) => new HtmlString('<a href="' . route('staff.show', $r) . '" class="fw-semibold">' . e($r->displayName()) . '</a><div class="text-muted fs-12">' . e($r->staff_number ?: 'no staff number') . ($r->designation ? ' · ' . e($r->designation) : '') . '</div>')],
            ['Department / unit', fn ($r) => $r->workplace() ?? '—'],
            ['Category', fn ($r) => collect([$r->category ? Lookups::label('staff_category', $r->category) : null, $r->payGrade(), $r->appointment_type ? Lookups::label('appointment_type', $r->appointment_type) : null])->filter()->implode(' · ') ?: '—'],
            ['Service', fn ($r) => $r->first_appointment_date ? intdiv($r->serviceMonths(), 12) . ' yr since ' . $r->first_appointment_date->format('Y') : '—'],
            ['Status', fn ($r) => new HtmlString('<span class="badge ' . ($r->employment_status === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary') . '">' . e(Lookups::label('employment_status', $r->employment_status)) . '</span>')],
        ];
    }

    public function show(Request $request, StaffProfile $staff): View
    {
        $staff->load('user', 'department', 'unit', 'supervisor', 'salaryStructure');
        abort_unless($this->scope($request)->coversStaff($staff), 403);
        $user = $request->user();
        $seesPay = $user->can('View payroll') || $user->can('Edit staff pay details') || $user->can('Change staff pay');

        return view('hr.staff-show', [
            'pagetitle' => $staff->displayName(), 'p' => $staff, 'retirement' => $this->retirement->for($staff), 'seesPay' => $seesPay,
            'items' => $seesPay ? StaffPayItem::with('creator')->where('user_id', $staff->user_id)->latest('id')->get() : collect(),
            'payslips' => $user->can('View payroll') ? Payslip::with('run')->where('user_id', $staff->user_id)->whereHas('run', fn ($q) => $q->where('status', '!=', 'draft'))->latest('id')->limit(24)->get() : collect(),
            'structures' => SalaryStructure::where('is_active', true)->orderBy('code')->pluck('code', 'id'),
            'banks' => Lookups::options('bank'), 'pfas' => Lookups::options('pfa'), 'states' => Lookups::options('state'),
            'leaveTaken' => \App\Models\LeaveRequest::with('type')->where('user_id', $staff->user_id)->where('status', 'approved')->latest('start_date')->limit(5)->get(),
        ]);
    }

    /** Pay, bank, pension and tax details. Every change goes on the operation history with what it was before. */
    public function updatePay(Request $request, StaffProfile $staff): RedirectResponse
    {
        $d = $request->validate([
            'salary_structure_id' => ['nullable', 'exists:salary_structures,id'], 'grade_level' => ['nullable', 'integer', 'between:1,20'], 'step' => ['nullable', 'integer', 'between:1,20'],
            'bank_name' => ['nullable', 'string', 'max:100'], 'bank_code' => ['nullable', 'string', 'max:10'],
            'bank_account_number' => ['nullable', 'digits_between:10,10'], 'bank_account_name' => ['nullable', 'string', 'max:150'],
            'pfa_code' => ['nullable', 'string', 'max:40'], 'rsa_pin' => ['nullable', 'regex:/^PEN\d{12}$/i'],
            'tax_id' => ['nullable', 'string', 'max:30'], 'tax_state' => ['nullable', 'string', 'max:40'], 'nhf_number' => ['nullable', 'string', 'max:30'],
        ], ['bank_account_number.digits_between' => 'A NUBAN account number has 10 digits.', 'rsa_pin.regex' => 'An RSA PIN is PEN followed by 12 digits.']);
        if (! empty($d['rsa_pin'])) {
            $d['rsa_pin'] = strtoupper($d['rsa_pin']);
        }
        // With a bank list set up, the bank is chosen by its code and its name is copied from the list.
        if (! empty($d['bank_code']) && ($name = Lookups::options('bank')[$d['bank_code']] ?? null)) {
            $d['bank_name'] = $name;
        }
        $before = $staff->only(array_keys($d));
        $staff->update($d);
        $changed = array_keys(array_diff_assoc(array_map('strval', $staff->only(array_keys($d))), array_map('strval', $before)));
        if ($changed) {
            $this->audit->note('staff_pay_details', 'payroll', $request->user(), 'pay_details_changed', $staff, array_intersect_key($before, array_flip($changed)), array_intersect_key($staff->only($changed), array_flip($changed)), null);
        }

        return back()->with('success', $changed ? 'Pay details saved.' : 'Nothing was changed.');
    }

    public function storeItem(Request $request, StaffProfile $staff): RedirectResponse
    {
        $d = $this->itemData($request);
        $item = StaffPayItem::create($d + ['user_id' => $staff->user_id, 'created_by' => $request->user()->id, 'is_active' => true]);
        $this->audit->note('staff_pay_item', 'payroll', $request->user(), 'pay_item_added', $item, null, $item->only(['kind', 'name', 'amount', 'balance', 'start_month', 'end_month']));

        return back()->with('success', "{$item->name} added.");
    }

    public function updateItem(Request $request, StaffPayItem $item): RedirectResponse
    {
        if ($request->boolean('stop')) {
            $item->update(['is_active' => false]);
            $this->audit->note('staff_pay_item', 'payroll', $request->user(), 'pay_item_stopped', $item, ['is_active' => true], ['is_active' => false]);

            return back()->with('success', "{$item->name} stopped. It will not appear on payrolls prepared from now on.");
        }
        $before = $item->only(['kind', 'name', 'amount', 'balance', 'start_month', 'end_month']);
        $item->update($this->itemData($request));
        $this->audit->note('staff_pay_item', 'payroll', $request->user(), 'pay_item_changed', $item, $before, $item->only(array_keys($before)));

        return back()->with('success', "{$item->name} saved.");
    }

    private function itemData(Request $request): array
    {
        $d = $request->validate([
            'kind' => ['required', 'in:earning,deduction'], 'name' => ['required', 'string', 'max:120'], 'amount' => ['required', 'numeric', 'min:0.01'],
            'balance' => ['nullable', 'numeric', 'min:0'], 'taxable' => ['nullable', 'boolean'],
            'start_month' => ['required', 'date_format:Y-m'], 'end_month' => ['nullable', 'date_format:Y-m', 'after_or_equal:start_month'],
            'reference' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $d['taxable'] = $request->boolean('taxable', true);
        if ($d['kind'] === 'earning') {
            $d['balance'] = null;
        }

        return $d;
    }

    public function retirements(Request $request): View
    {
        $months = max(1, min(120, (int) $request->query('months', 24)));
        $scope = $this->scope($request);

        return view('hr.retirements', [
            'pagetitle' => 'Retirements due', 'months' => $months,
            'rows' => $this->retirement->due($months, fn ($q) => $scope->staffProfiles($q)),
            'rules' => collect(RetirementRules::DEFAULTS)->map(fn ($v, $k) => $this->retirement->setting($k)),
            'missing' => $scope->staffProfiles(StaffProfile::query())->whereNull('exit_date')->where(fn ($q) => $q->whereNull('first_appointment_date')->orWhereHas('user', fn ($u) => $u->whereNull('date_of_birth')))->count(),
        ]);
    }
}
