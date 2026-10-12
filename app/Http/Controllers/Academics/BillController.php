<?php

namespace App\Http\Controllers\Academics;

use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\StudyCategory;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/**
 * "School bills": what each bill item costs for a programme + level in a session.
 */
class BillController extends CrudController
{
    protected string $permission = 'school bills';
    protected string $title = 'School Bills';
    protected string $singular = 'Bill';
    protected string $icon = 'ri-bill-line';
    protected string $routeBase = 'bills.structure';
    protected string $intro = 'Set what each bill item costs for a programme and level in a session. A charge can apply to all students, or only fresh or returning ones. Use "Copy bills" to roll a session forward.';
    protected int $perPage = 30;
    protected ?string $extraView = 'academics.partials.bill-extra';

    public function __construct(private \App\Services\BillMatcher $matcher)
    {
        parent::__construct();
        $this->middleware('permission:Copy school bills to another session')->only('copy');
    }

    protected function model(): string
    {
        return Bill::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with(['session', 'programme.studyCategory', 'faculty', 'department', 'studyCategory', 'semesterType', 'item'])->reorder()
            ->orderByDesc('academic_session_id')->orderBy('bill_item_id')->orderBy('programme_id')->orderBy('level');
    }

    protected function sessionOptions(): array
    {
        return AcademicSession::orderByDesc('name')->pluck('name', 'id')->all();
    }

    protected function programmeOptions(): array
    {
        return Programme::orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->id => $p->name . ' (' . $p->code . ')'])->all();
    }

    protected function levelOptions(): array
    {
        return \App\Support\Levels::options();
    }

    protected function filters(): array
    {
        return [
            ['academic_session_id', 'Session', $this->sessionOptions()],
            ['programme_id', 'Programme', $this->programmeOptions()],
            ['level', 'Level', $this->levelOptions()],
        ];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $programme = Programme::find($request->input('programme_id'));
        
        return [
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'faculty_id'          => ['nullable', 'exists:faculties,id'],
            'department_id'       => ['nullable', 'exists:departments,id'],
            'study_category_id'   => ['nullable', 'exists:study_categories,id'],
            'programme_id'        => ['nullable', 'exists:programmes,id'],
            'level' => [
                'nullable', 'integer',
                function ($attribute, $value, $fail) use ($programme) {
                    if ($value && $programme && ! in_array((int) $value, $programme->levels(), true)) {
                        $fail("{$programme->name} runs levels " . implode(', ', $programme->levels()) . '.');
                    }
                },
            ],
            'semester_type_id' => ['nullable', 'exists:semester_types,id'],
            'bill_item_id'     => [
                'required', 'exists:bill_items,id',
                function ($attribute, $value, $fail) use ($request, $record) {
                    $same = Bill::where('academic_session_id', $request->input('academic_session_id'))->where('bill_item_id', $value)
                        ->where('student_type', $request->input('student_type'))->where('nationality', $request->input('nationality') ?: 'any')
                        ->when($record, fn ($q) => $q->where('id', '!=', $record->id));
                    foreach (['faculty_id', 'department_id', 'study_category_id', 'programme_id', 'level', 'semester_type_id'] as $f) {
                        $request->filled($f) ? $same->where($f, $request->input($f)) : $same->whereNull($f);
                    }
                    if ($same->exists()) {
                        $fail('A bill for this item already exists for exactly the same people. Edit that one instead.');
                    }
                },
            ],
            'student_type' => ['required', Rule::in(array_keys(Bill::STUDENT_TYPES))],
            'nationality'  => ['sometimes', 'required', Rule::in(array_keys(Bill::NATIONALITIES))],
            'amount'       => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'installments' => ['sometimes', 'required', 'integer', 'between:1,3'],
            'pct_1' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pct_2' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pct_3' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'opens_at'  => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $data += ['nationality' => 'any', 'installments' => 1];
        $n = (int) $data['installments'];
        $p = [];
        foreach ([1, 2, 3] as $i) {
            if ($i <= $n && isset($data["pct_$i"])) {
                $p[] = (float) $data["pct_$i"];
            }
            unset($data["pct_$i"]);
        }

        if ($n > 1 && (count($p) !== $n || abs(array_sum($p) - 100) > 0.01)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['pct_1' => "Give {$n} percentages that add up to 100."]);
        }
        $data['installment_percents'] = $n > 1 ? $p : null;

        return $data;
    }

    protected function fields(): array
    {
        $current = AcademicSession::current();
        $any = ['blank' => 'All'];

        return [
            ['name' => 'academic_session_id', 'lookup' => 'sessions', 'label' => 'Session', 'type' => 'select', 'options' => $this->sessionOptions(), 'required' => true, 'col' => 4, 'default' => $current?->id],
            ['name' => 'bill_item_id', 'lookup' => 'bill-items', 'lookup_active' => true, 'label' => 'Bill item', 'type' => 'select', 'options' => BillItem::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 5],
            ['name' => 'amount', 'label' => 'Amount (₦)', 'type' => 'number', 'required' => true, 'col' => 3, 'step' => '0.01'],

            ['name' => 'faculty_id', 'lookup' => 'faculties', 'label' => 'Faculty / School', 'type' => 'select', 'options' => Faculty::orderBy('name')->pluck('name', 'id')->all(), 'col' => 4, 'help' => 'Leave on All to charge every faculty.'] + $any,
            ['name' => 'department_id', 'lookup' => 'departments', 'lookup_parent' => 'faculty_id', 'label' => 'Department', 'type' => 'select', 'options' => Department::orderBy('name')->pluck('name', 'id')->all(), 'col' => 4] + $any,
            ['name' => 'study_category_id', 'lookup' => 'study-categories', 'label' => 'Student category', 'type' => 'select', 'options' => StudyCategory::orderBy('name')->pluck('name', 'id')->all(), 'col' => 4] + $any,
            ['name' => 'programme_id', 'lookup' => 'programmes', 'lookup_parent' => 'department_id', 'label' => 'Programme', 'type' => 'select', 'options' => $this->programmeOptions(), 'col' => 5] + $any,
            ['name' => 'level', 'label' => 'Level', 'type' => 'select', 'options' => $this->levelOptions(), 'col' => 3] + $any,
            ['name' => 'semester_type_id', 'lookup' => 'semester-orders', 'label' => 'Billed for', 'type' => 'select', 'options' => \App\Models\SemesterType::options(), 'col' => 4, 'blank' => 'The whole session', 'help' => 'Pick a semester to bill this on a separate semester invoice.'],
            ['name' => 'student_type', 'label' => 'Fresh or returning', 'type' => 'select', 'options' => Bill::STUDENT_TYPES, 'required' => true, 'col' => 6, 'default' => 'all'],
            ['name' => 'nationality', 'label' => 'Nationality', 'type' => 'select', 'options' => Bill::NATIONALITIES, 'required' => true, 'col' => 6, 'default' => 'any', 'help' => 'Add a second bill for the same item marked "Foreign students only" to charge them a different amount.'],

            ['name' => 'installments', 'label' => 'Pay in', 'type' => 'select', 'options' => [1 => 'One payment', 2 => '2 instalments', 3 => '3 instalments'], 'required' => true, 'col' => 4, 'default' => 1],
            ['name' => 'pct_1', 'label' => '1st instalment %', 'type' => 'number', 'col' => 2, 'step' => '0.01'],
            ['name' => 'pct_2', 'label' => '2nd %', 'type' => 'number', 'col' => 3, 'step' => '0.01'],
            ['name' => 'pct_3', 'label' => '3rd %', 'type' => 'number', 'col' => 3, 'step' => '0.01'],
            ['name' => 'opens_at', 'label' => 'Payment opens', 'type' => 'datetime-local', 'col' => 6, 'help' => 'Leave blank to open straight away.'],
            ['name' => 'closes_at', 'label' => 'Payment closes', 'type' => 'datetime-local', 'col' => 6, 'help' => 'Leave blank for no deadline. Staff can still record payments after it.'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Session', fn ($r) => $r->session->name],
            ['Bill item', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->item->name) . '</span>' . ($r->item->is_compulsory ? '' : ' <span class="badge bg-info-subtle text-info">Optional</span>'))],
            ['Applies to', fn ($r) => $r->targetLabel()],
            ['Amount', fn ($r) => new HtmlString('<span class="fw-semibold">₦' . number_format((float) $r->amount, 2) . '</span>' . ($r->installments > 1 ? '<div class="text-muted fs-12">' . $r->installments . ' instalments (' . implode(' / ', $this->matcher->percents($r)) . '%)</div>' : ''))],
            ['Payment window', fn ($r) => $r->opens_at || $r->closes_at ? ($r->opens_at?->format('j M Y H:i') ?? 'Now') . ' to ' . ($r->closes_at?->format('j M Y H:i') ?? 'no deadline') : 'Always open'],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }

    protected function viewData(Request $request): array
    {
        // Totals for what is currently filtered, per target group (compulsory bills only; optional ones are chosen by the student).
        $summary = $this->query($request)->reorder()->where('is_active', true)->whereHas('item', fn ($q) => $q->where('is_compulsory', true))->get()
            ->groupBy(fn ($b) => $b->session->name . '|' . $b->targetLabel())
            ->map(fn ($g) => [
                'session' => $g->first()->session->name,
                'target'  => $g->first()->targetLabel(),
                'items'   => $g->count(),
                'total'   => $g->sum(fn ($b) => (float) $b->amount),
            ])->values();

        return [
            'summary'        => $summary,
            'sessionOptions' => $this->sessionOptions(),
            'programmes'     => $this->programmeOptions(),
        ];
    }

    protected function recordData(Model $row, array $fields): array
    {
        $out = parent::recordData($row, $fields);
        foreach ([1, 2, 3] as $i) {
            $out["pct_$i"] = $row->installment_percents[$i - 1] ?? null;
        }

        return $out;
    }

    /** Copy every active bill of one session into another, optionally raising/lowering amounts by a percentage. */
    public function copy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_session_id' => ['required', 'exists:academic_sessions,id'],
            'to_session_id'   => ['required', 'exists:academic_sessions,id', 'different:from_session_id'],
            'programme_id'    => ['nullable', 'exists:programmes,id'],
            'percent'         => ['nullable', 'numeric', 'min:-90', 'max:500'],
        ]);

        $factor = 1 + ((float) ($data['percent'] ?? 0)) / 100;
        $created = 0;
        $skipped = 0;

        Bill::where('academic_session_id', $data['from_session_id'])
            ->when($data['programme_id'] ?? null, fn ($q, $p) => $q->where('programme_id', $p))
            ->where('is_active', true)
            ->each(function (Bill $bill) use ($data, $factor, &$created, &$skipped) {
                $keys = ['faculty_id', 'department_id', 'study_category_id', 'programme_id', 'level', 'semester_type_id', 'bill_item_id', 'student_type', 'nationality'];
                $exists = Bill::where('academic_session_id', $data['to_session_id']);
                foreach ($keys as $k) {
                    $bill->{$k} === null ? $exists->whereNull($k) : $exists->where($k, $bill->{$k});
                }
                if ($exists->exists()) {
                    $skipped++;

                    return;
                }
                // Payment windows are dates for the old session, so they are not carried over.
                $copy = $bill->replicate();
                $copy->academic_session_id = $data['to_session_id'];
                $copy->amount = round($bill->amount * $factor, 2);
                $copy->opens_at = $copy->closes_at = null;
                $copy->is_active = true;
                $copy->save();
                $created++;
            });

        return back()->with('success', "Copied {$created} bill(s)" . ($skipped ? "; {$skipped} already existed and were left unchanged." : '.'));
    }
}
