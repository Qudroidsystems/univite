<?php

namespace App\Http\Controllers\Academics;

use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Course;
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

    public function __construct()
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
        return parent::query($request)->with(['session', 'programme', 'item'])->reorder()
            ->orderByDesc('academic_session_id')->orderBy('programme_id')->orderBy('level')->orderBy('bill_item_id');
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
            'programme_id'        => ['required', 'exists:programmes,id'],
            'level' => [
                'required', 'integer',
                function ($attribute, $value, $fail) use ($programme) {
                    if ($programme && ! in_array((int) $value, $programme->levels(), true)) {
                        $fail("{$programme->name} runs levels " . implode(', ', $programme->levels()) . '.');
                    }
                },
            ],
            'bill_item_id' => ['required', 'exists:bill_items,id'],
            'student_type' => [
                'required', Rule::in(array_keys(Bill::STUDENT_TYPES)),
                Rule::unique('bills')
                    ->where('academic_session_id', $request->input('academic_session_id'))
                    ->where('programme_id', $request->input('programme_id'))
                    ->where('level', $request->input('level'))
                    ->where('bill_item_id', $request->input('bill_item_id'))
                    ->ignore($record?->id),
            ],
            'amount'    => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        $current = AcademicSession::current();

        return [
            ['name' => 'academic_session_id', 'label' => 'Session', 'type' => 'select', 'options' => $this->sessionOptions(), 'required' => true, 'col' => 6, 'default' => $current?->id],
            ['name' => 'programme_id', 'label' => 'Programme', 'type' => 'select', 'options' => $this->programmeOptions(), 'required' => true, 'col' => 6],
            ['name' => 'level', 'label' => 'Level', 'type' => 'select', 'options' => $this->levelOptions(), 'required' => true, 'col' => 4],
            ['name' => 'bill_item_id', 'label' => 'Bill item', 'type' => 'select', 'options' => BillItem::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 8],
            ['name' => 'student_type', 'label' => 'Applies to', 'type' => 'select', 'options' => Bill::STUDENT_TYPES, 'required' => true, 'col' => 6, 'default' => 'all'],
            ['name' => 'amount', 'label' => 'Amount (₦)', 'type' => 'number', 'required' => true, 'col' => 6, 'step' => '0.01'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'col' => 12, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Session', fn ($r) => $r->session->name],
            ['Programme', fn ($r) => new HtmlString('<span class="fw-semibold">' . e($r->programme->name) . '</span>')],
            ['Level', fn ($r) => $r->level . 'L'],
            ['Bill item', fn ($r) => $r->item->name],
            ['Applies to', fn ($r) => Bill::STUDENT_TYPES[$r->student_type] ?? $r->student_type],
            ['Amount', fn ($r) => new HtmlString('<span class="fw-semibold">₦' . number_format((float) $r->amount, 2) . '</span>')],
            ['Status', fn ($r) => new HtmlString($r->is_active ? '<span class="badge bg-success-subtle text-success">Active</span>' : '<span class="badge bg-secondary-subtle text-secondary">Inactive</span>')],
        ];
    }

    protected function viewData(Request $request): array
    {
        // Totals for what is currently filtered, per programme + level + student type.
        $summary = $this->query($request)->reorder()->where('is_active', true)->get()
            ->groupBy(fn ($b) => $b->session->name . '|' . $b->programme->name . '|' . $b->level . '|' . $b->student_type)
            ->map(fn ($g) => [
                'session'   => $g->first()->session->name,
                'programme' => $g->first()->programme->name,
                'level'     => $g->first()->level,
                'type'      => Bill::STUDENT_TYPES[$g->first()->student_type],
                'items'     => $g->count(),
                'total'     => $g->sum(fn ($b) => (float) $b->amount),
            ])->values();

        return [
            'summary'        => $summary,
            'sessionOptions' => $this->sessionOptions(),
            'programmes'     => $this->programmeOptions(),
        ];
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
                $bill = Bill::firstOrCreate(
                    [
                        'academic_session_id' => $data['to_session_id'],
                        'programme_id'        => $bill->programme_id,
                        'level'               => $bill->level,
                        'bill_item_id'        => $bill->bill_item_id,
                        'student_type'        => $bill->student_type,
                    ],
                    ['amount' => round($bill->amount * $factor, 2), 'is_active' => true]
                );
                $bill->wasRecentlyCreated ? $created++ : $skipped++;
            });

        return back()->with('success', "Copied {$created} bill(s)" . ($skipped ? "; {$skipped} already existed and were left unchanged." : '.'));
    }
}
