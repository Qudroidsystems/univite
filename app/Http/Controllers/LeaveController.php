<?php

namespace App\Http\Controllers;

use App\Data\Leave\LeaveApplication;
use App\Models\Department;
use App\Models\LeaveDocument;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\LeaveService;
use App\Support\LeaveLetters;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;

/** Leave for every member of staff: balances and countdown, applying, approving, acting, cancelling, resuming, letters. */
class LeaveController extends Controller
{
    public function __construct(private LeaveService $leave)
    {
        $this->middleware(function ($request, $next) {
            abort_if($request->user()?->student, 403, 'Leave is for staff.');

            return $next($request);
        });
    }

    /** Leave types this person could apply for (open, and for their gender when a type is restricted). */
    private function typesFor(User $user): Collection
    {
        return LeaveType::with('entitlements')->where('is_active', true)->orderBy('sort_order')->get()
            ->filter(fn ($t) => ! $t->gender || ! $user->gender || $user->gender === $t->gender)->values();
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $year = (int) $request->query('year', now()->year);
        $requests = LeaveRequest::with('type', 'steps')->where('user_id', $user->id)->latest('start_date')->get();
        $current = $requests->where('status', 'approved')->whereNull('resumed_on')->filter(fn ($r) => $r->expected_resumption->gte(today()->subDays(30)))->sortBy('start_date')->first();

        return view('leave.index', [
            'pagetitle' => 'My leave', 'year' => $year, 'requests' => $requests, 'current' => $current,
            'balances' => $this->typesFor($user)->map(fn ($t) => ['type' => $t, 'balance' => $this->leave->balance($user, $t, $year)]),
            'acting' => LeaveRequest::with('user', 'type')->where('acting_officer_id', $user->id)->whereIn('status', ['pending', 'approved'])->whereNull('resumed_on')->whereDate('expected_resumption', '>=', today())->orderBy('start_date')->get(),
            'waiting' => $this->leave->waitingFor($user)->count(),
            'profile' => $user->staffProfile,
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new LeaveRequest(['start_date' => today()->addDay(), 'end_date' => today()->addDay()]));
    }

    public function edit(Request $request, LeaveRequest $leave): View
    {
        abort_unless($leave->user_id === $request->user()->id && $leave->status === 'returned', 403);

        return $this->form($request, $leave);
    }

    private function form(Request $request, LeaveRequest $leave): View
    {
        $user = $request->user();
        $dept = $user->staffProfile?->department_id;

        return view('leave.form', [
            'pagetitle' => $leave->exists ? 'Correct ' . $leave->reference : 'Apply for leave', 'leave' => $leave,
            'types' => $this->typesFor($user),
            'balances' => $this->typesFor($user)->mapWithKeys(fn ($t) => [$t->id => $this->leave->balance($user, $t, now()->year, $leave->id)->available()]),
            // Colleagues first (same department), then everyone else on staff.
            'staff' => User::whereDoesntHave('student')->where('is_disabled', false)->where('id', '!=', $user->id)->with('staffProfile')->orderBy('name')->get()
                ->sortBy(fn ($u) => $dept && $u->staffProfile?->department_id === $dept ? 0 : 1)->mapWithKeys(fn ($u) => [$u->id => $u->name . ($u->staffProfile?->designation ? ' · ' . $u->staffProfile->designation : '')]),
        ]);
    }

    /** Live check while filling the form: days, balance, problems and warnings. */
    public function check(Request $request): JsonResponse
    {
        $d = $request->validate(['leave_type_id' => ['required', 'exists:leave_types,id'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date'], 'acting_officer_id' => ['nullable', 'integer'], 'documents' => ['nullable', 'integer'], 'existing' => ['nullable', 'integer']]);
        $existing = ! empty($d['existing']) ? LeaveRequest::where('user_id', $request->user()->id)->find($d['existing']) : null;
        $type = LeaveType::with('entitlements')->findOrFail($d['leave_type_id']);
        $start = Carbon::parse($d['start_date'])->startOfDay();
        $end = Carbon::parse($d['end_date'])->startOfDay();
        $a = $this->leave->assess($request->user(), $type, $start, $end, ! empty($d['acting_officer_id']) ? (int) $d['acting_officer_id'] : null, (int) ($d['documents'] ?? 0), $existing);

        return response()->json($a->toArray() + [
            'resume' => $end->gte($start) ? LeaveService::nextWorkingDay($end)->format('l j M Y') : null,
            'balance' => $type->deducts_balance ? $this->leave->balance($request->user(), $type, $start->year, $existing?->id)->available() : null,
            'unit' => $type->working_days ? 'working day(s)' : 'calendar day(s)',
        ]);
    }

    private function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'exists:leave_types,id'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:2000'], 'address_during_leave' => ['nullable', 'string', 'max:255'], 'phone_during_leave' => ['nullable', 'string', 'max:30'],
            'acting_officer_id' => ['nullable', 'exists:users,id'], 'documents' => ['nullable', 'array', 'max:5'], 'documents.*' => ['file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        try {
            $r = $this->leave->apply($request->user(), LeaveApplication::fromArray($data, $request->file('documents', [])));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('leave.show', $r)->with('success', "Application {$r->reference} submitted. You will be told of each decision.");
    }

    public function update(Request $request, LeaveRequest $leave): RedirectResponse
    {
        abort_unless($leave->user_id === $request->user()->id && $leave->status === 'returned', 403);
        $data = $request->validate($this->rules());
        try {
            $r = $this->leave->apply($request->user(), LeaveApplication::fromArray($data, $request->file('documents', [])), $leave);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('leave.show', $r)->with('success', "{$r->reference} corrected and sent for approval again.");
    }

    public function show(Request $request, LeaveRequest $leave): View
    {
        $leave->load('user.staffProfile.department', 'type', 'steps.actor', 'steps.approverUser', 'documents', 'actingOfficer', 'decider');
        $user = $request->user();
        abort_unless($this->leave->canView($user, $leave), 403);
        $step = $leave->currentStep();

        return view('leave.show', [
            'pagetitle' => $leave->reference, 'r' => $leave, 'own' => $user->id === $leave->user_id,
            'canDecide' => $this->leave->canAct($user, $leave),
            'approvers' => $step ? $this->leave->approversFor($step, $leave) : collect(),
            'balance' => $leave->type->deducts_balance ? $this->leave->balance($leave->user, $leave->type, $leave->year) : null,
            'letters' => $this->letters($leave),
            'history' => LeaveRequest::with('type')->where('user_id', $leave->user_id)->where('id', '!=', $leave->id)->where('status', 'approved')->latest('start_date')->limit(5)->get(),
        ]);
    }

    /** The letters this application has so far. */
    private function letters(LeaveRequest $r): array
    {
        return array_values(array_filter([
            'acknowledgement',
            $r->status === 'approved' ? 'approval' : null,
            $r->status === 'rejected' ? 'rejection' : null,
            $r->status === 'returned' ? 'returned' : null,
            $r->status === 'cancelled' ? 'cancelled' : null,
            $r->resumed_on ? 'resumption' : null,
        ]));
    }

    public function decide(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $d = $request->validate(['action' => ['required', 'in:approve,reject,return'], 'comment' => ['nullable', 'string', 'max:500']]);
        try {
            $r = $this->leave->decide($leave, $request->user(), $d['action'], $d['comment'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $msg = match ($r->status) {
            'approved' => 'Approved. ' . $r->user->name . ' has been told and the approval letter sent.',
            'pending' => 'Your approval is recorded. It has moved to ' . ($r->currentStep()?->label ?? 'the next step') . '.',
            'rejected' => 'Not approved. ' . $r->user->name . ' has been told why.',
            default => 'Returned to ' . $r->user->name . ' for correction.',
        };

        return redirect()->route($request->input('back') === 'approvals' ? 'leave.approvals' : 'leave.show', $request->input('back') === 'approvals' ? [] : $r)->with('success', $msg);
    }

    public function acting(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $request->validate(['accept' => ['required', 'boolean']]);
        try {
            $this->leave->respondAsActing($leave, $request->user(), $request->boolean('accept'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $request->boolean('accept') ? 'You have accepted to act. ' . $leave->user->name . ' has been told.' : 'You have declined. ' . $leave->user->name . ' has been told and can name someone else.');
    }

    public function cancel(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->leave->cancel($leave, $request->user(), $d['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$leave->reference} cancelled. No days are charged for it.");
    }

    public function resume(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $own = $request->user()->id === $leave->user_id;
        abort_unless($own || $request->user()->can('Record staff resumption'), 403);
        $d = $request->validate(['resumed_on' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:500']]);
        if ($own && $leave->resumed_on) {
            return back()->with('error', 'Your resumption is already recorded. Ask HR if it needs changing.');
        }
        try {
            $r = $this->leave->recordResumption($leave, Carbon::parse($d['resumed_on'])->startOfDay(), $request->user(), $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Resumption recorded for ' . $r->resumed_on->format('j M Y') . ($r->late_days ? " ({$r->late_days} working day(s) late)." : '.') . ($r->days_used !== null ? " Only {$r->days_used} day(s) are charged; the rest go back to the balance." : ''));
    }

    public function document(Request $request, LeaveDocument $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($this->leave->canOpenDocument($request->user(), $document->load('request')), 403, 'This document is confidential.');
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name);
    }

    public function letter(Request $request, LeaveRequest $leave, string $kind): Response
    {
        abort_unless($this->leave->canView($request->user(), $leave->load('steps')), 403);
        abort_unless(in_array($kind, $this->letters($leave), true), 404);
        $html = view('emails.document', ['doc' => LeaveLetters::doc($leave, $kind)])->render();
        $bar = '<div class="no-print" style="text-align:center;padding:10px"><button onclick="window.print()" style="padding:6px 16px">Print</button></div><style>@media print{.no-print{display:none}}</style>';

        return response(preg_replace('/<body([^>]*)>/', '<body$1>' . $bar, $html, 1));
    }

    public function approvals(Request $request): View
    {
        $user = $request->user();

        return view('leave.approvals', [
            'pagetitle' => 'Leave approvals', 'waiting' => $this->leave->waitingFor($user),
            'decided' => LeaveRequest::with('user', 'type', 'steps')->whereHas('steps', fn ($q) => $q->where('acted_by', $user->id))->latest('updated_at')->limit(30)->get(),
        ]);
    }

    /** Who is away when: everyone for leave administrators, the person's own department for everyone else. */
    public function calendar(Request $request): View
    {
        $user = $request->user();
        $month = Carbon::parse($request->query('month', now()->format('Y-m')) . '-01')->startOfMonth();
        $all = $user->can('View leave applications') || $user->can('Approve leave for any staff');
        $dept = $all ? ($request->integer('department_id') ?: null) : $user->staffProfile?->department_id;
        $people = $dept ? StaffProfile::where('department_id', $dept)->pluck('user_id') : null;
        $rows = LeaveRequest::with('user.staffProfile.department', 'type')->whereIn('status', $request->boolean('pending') ? ['approved', 'pending'] : ['approved'])
            ->whereDate('start_date', '<=', $month->copy()->endOfMonth())->whereDate('end_date', '>=', $month)
            ->when($people !== null, fn ($q) => $q->whereIn('user_id', $people))
            ->when(! $all && $people === null, fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('start_date')->get();

        return view('leave.calendar', [
            'pagetitle' => 'Leave calendar', 'month' => $month, 'rows' => $rows, 'all' => $all, 'department' => $dept,
            'departments' => $all ? Department::orderBy('name')->pluck('name', 'id') : collect(),
        ]);
    }
}
