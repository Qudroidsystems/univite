<?php

namespace App\Services;

use App\Data\Leave\LeaveApplication;
use App\Data\Leave\LeaveAssessment;
use App\Data\Leave\LeaveBalance;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveApprovalStep;
use App\Models\LeaveDocument;
use App\Models\LeaveEntitlement;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\LeaveType;
use App\Models\Setting;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Staff leave from application to resumption: eligibility and balance checks, a configurable approval route
 * (supervisor, HOD, dean, or anyone with a role such as HR or Registrar), notifications and letters at every step,
 * reminders and escalation, coverage warnings, and resumption with late and early returns.
 */
class LeaveService
{
    public const SETTINGS = [
        'coverage_percent' => ['Warn when this share of a department would be away at once (%)', '25'],
        'remind_before' => ['Remind staff this many days before their leave starts', '3'],
        'remind_left' => ['Remind staff on leave when this many working days are left (comma-separated)', '7,1'],
        'late_grace' => ['Flag a late resumption after this many working days', '1'],
        'weekend' => ['Days that are not working days (1 = Monday ... 7 = Sunday, comma-separated)', '6,7'],
    ];

    public function __construct(private OperationAudit $audit, private Messenger $messenger, private DocumentMailer $mailer, private TimetableService $timetables)
    {
    }

    public static function setting(string $key): string
    {
        return Setting::get("leave.{$key}", self::SETTINGS[$key][1]) ?? self::SETTINGS[$key][1];
    }

    public function profile(User $user): StaffProfile
    {
        return $user->staffProfile ?? StaffProfile::firstOrCreate(['user_id' => $user->id]);
    }

    // ---- days and balances ----------------------------------------------------------------------------

    public static function isWorkingDay(CarbonInterface $d): bool
    {
        $weekend = array_map('intval', explode(',', self::setting('weekend')));

        return ! in_array($d->dayOfWeekIso, $weekend, true) && ! Holiday::nameOn($d);
    }

    public function countDays(LeaveType $type, CarbonInterface $start, CarbonInterface $end): int
    {
        if ($end->lt($start)) {
            return 0;
        }
        if (! $type->working_days) {
            return (int) $start->diffInDays($end) + 1;
        }
        $n = 0;
        foreach (CarbonPeriod::create($start, $end) as $d) {
            self::isWorkingDay($d) && $n++;
        }

        return $n;
    }

    public static function nextWorkingDay(CarbonInterface $after): Carbon
    {
        $d = Carbon::parse($after)->addDay();
        for ($i = 0; $i < 60 && ! self::isWorkingDay($d); $i++) {
            $d->addDay();
        }

        return $d;
    }

    public static function workingDaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $n = 0;
        foreach (CarbonPeriod::create($from, $to) as $d) {
            self::isWorkingDay($d) && $n++;
        }

        return $n;
    }

    public function entitlement(User $user, LeaveType $type): int
    {
        $profile = $this->profile($user);
        $rule = $type->entitlements->filter(fn (LeaveEntitlement $e) => $e->matches($profile))
            ->sortByDesc(fn ($e) => [$e->specificity(), $e->min_years, $e->days])->first();

        return (int) ($rule?->days ?? $type->default_days);
    }

    public function balance(User $user, LeaveType $type, int $year, ?int $ignoreRequest = null): LeaveBalance
    {
        $type->loadMissing('entitlements');
        $ledger = LeaveLedgerEntry::where(['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year])->get();
        $requests = LeaveRequest::where(['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year])->whereIn('status', ['pending', 'returned', 'approved'])
            ->when($ignoreRequest, fn ($q) => $q->where('id', '!=', $ignoreRequest))->get();
        return new LeaveBalance(
            entitled: $this->entitlement($user, $type),
            carried: (float) $ledger->where('kind', 'carryover')->sum('days'),
            adjusted: (float) $ledger->where('kind', 'adjustment')->sum('days'),
            taken: (int) $requests->where('status', 'approved')->sum(fn ($r) => $r->days_charged),
            pending: (int) $requests->whereIn('status', ['pending', 'returned'])->sum('days'),
        );
    }

    // ---- applying -------------------------------------------------------------------------------------

    /** Everything wrong with an application (errors) and everything an approver should know (warnings). */
    public function assess(User $user, LeaveType $type, Carbon $start, Carbon $end, ?int $actingId = null, int $documents = 0, ?LeaveRequest $existing = null): LeaveAssessment
    {
        $errors = [];
        $warnings = [];
        $profile = $this->profile($user);
        $days = $this->countDays($type, $start, $end);

        if ($user->student) {
            $errors[] = 'Leave is for staff. Students cannot apply.';
        }
        if (! $type->is_active) {
            $errors[] = "{$type->name} is not open for applications.";
        }
        if ($type->gender && $user->gender && strtolower($user->gender) !== $type->gender) {
            $errors[] = "{$type->name} is only for " . ($type->gender === 'female' ? 'female' : 'male') . ' staff.';
        }
        if ($type->min_service_months && $profile->serviceMonths($start) < $type->min_service_months) {
            $errors[] = "{$type->name} needs at least {$type->min_service_months} months of service" . ($profile->first_appointment_date ? '' : ' (your first appointment date is not on record; ask HR to add it)') . '.';
        }
        if ($end->lt($start)) {
            $errors[] = 'The last day cannot be before the first day.';
        } elseif ($days === 0) {
            $errors[] = 'Those dates have no working days in them.';
        }
        if ($type->notice_days && $start->lt(now()->startOfDay()->addDays($type->notice_days))) {
            $errors[] = "{$type->name} must be applied for at least {$type->notice_days} days before it starts.";
        }
        if ($type->max_days_per_request && $days > $type->max_days_per_request) {
            $errors[] = "At most {$type->max_days_per_request} days of {$type->name} can be taken at once.";
        }
        if ($type->deducts_balance) {
            $bal = $this->balance($user, $type, $start->year, $existing?->id);
            if ($days > $bal->available()) {
                $errors[] = "You asked for {$days} day(s) but have {$bal->available()} day(s) of {$type->name} left for {$start->year}.";
            }
        }
        $overlap = LeaveRequest::where('user_id', $user->id)->whereIn('status', ['pending', 'returned', 'approved'])
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->first();
        if ($overlap) {
            $errors[] = "These dates overlap your application {$overlap->reference} ({$overlap->start_date->format('j M')}–{$overlap->end_date->format('j M')}).";
        }
        if ($type->needs_document && $documents === 0 && ! $existing?->documents()->exists()) {
            $errors[] = 'Attach the ' . ($type->document_label ?: 'supporting document') . '.';
        }
        if ($actingId === $user->id) {
            $errors[] = 'You cannot be your own acting officer.';
        }
        if (($type->needs_acting_officer || $profile->critical_role) && ! $actingId) {
            $type->needs_acting_officer ? $errors[] = "Name an acting officer for {$type->name}." : $warnings[] = 'This role must be covered, but no acting officer was named.';
        }
        if ($actingId && LeaveRequest::where('user_id', $actingId)->where('status', 'approved')->whereDate('start_date', '<=', $end)->whereDate('expected_resumption', '>', $start)->exists()) {
            $warnings[] = 'The acting officer is also on leave during part of this period.';
        }
        if ($w = $this->coverage($user, $start, $end, $existing?->id)) {
            $warnings[] = $w;
        }

        return new LeaveAssessment($days, $errors, $warnings);
    }

    /** "3 of 8 staff in Computer Science would be away" when that crosses the configured share. */
    public function coverage(User $user, Carbon $start, Carbon $end, ?int $ignore = null): ?string
    {
        $dept = $this->profile($user)->department_id;
        if (! $dept) {
            return null;
        }
        $colleagues = StaffProfile::where('department_id', $dept)->pluck('user_id');
        $away = LeaveRequest::whereIn('user_id', $colleagues)->where('user_id', '!=', $user->id)->whereIn('status', ['pending', 'approved'])
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->whereNull('resumed_on')
            ->whereDate('start_date', '<=', $end)->whereDate('expected_resumption', '>', $start)->distinct()->count('user_id');
        $size = max(1, $colleagues->count());
        $share = ($away + 1) / $size * 100;
        $limit = (float) self::setting('coverage_percent');

        return $away > 0 && $share >= $limit
            ? sprintf('%d of %d staff in %s would be away at the same time (%d%%), including this application.', $away + 1, $size, Department::find($dept)?->name ?? 'the department', round($share))
            : null;
    }

    /** Submit an application, or resubmit one that was returned for correction. */
    public function apply(User $user, LeaveApplication $data, ?LeaveRequest $existing = null): LeaveRequest
    {
        $type = LeaveType::with('entitlements')->findOrFail($data->leaveTypeId);
        $start = $data->start;
        $end = $data->end;
        $acting = $data->actingOfficerId;
        $files = $data->documents;
        $check = $this->assess($user, $type, $start, $end, $acting, count($files), $existing);
        if (! $check->passes()) {
            throw new RuntimeException(implode(' ', $check->errors));
        }

        $request = DB::transaction(function () use ($user, $type, $start, $end, $acting, $data, $files, $check, $existing) {
            $fields = [
                'user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $start->year, 'start_date' => $start, 'end_date' => $end, 'days' => $check->days,
                'expected_resumption' => self::nextWorkingDay($end), 'reason' => $data->reason, 'address_during_leave' => $data->addressDuringLeave,
                'phone_during_leave' => $data->phoneDuringLeave, 'acting_officer_id' => $acting, 'acting_status' => $acting ? 'pending' : null,
                'status' => 'pending', 'current_step' => 1, 'submitted_at' => now(), 'warnings' => $check->warnings ?: null,
            ];
            if ($existing) {
                $before = $existing->only(['start_date', 'end_date', 'days', 'status']);
                $existing->update($fields);
                $existing->steps()->delete();
                $r = $existing;
            } else {
                $r = LeaveRequest::create($fields + ['reference' => $this->nextReference($start->year)]);
            }
            foreach ($files as $file) {
                $path = $file->store("leave/{$r->id}", 'local');
                LeaveDocument::create(['leave_request_id' => $r->id, 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType(), 'size' => $file->getSize(), 'confidential' => $type->confidential, 'uploaded_by' => $user->id]);
            }
            $this->buildRoute($r->fresh(['user', 'type']));

            $op = $this->audit->begin('leave_submit', 'leave', $user, null, null, ['request' => $r->reference]);
            $this->audit->record($op, $existing ? 'resubmitted' : 'submitted', $r, $existing ? $before : null, ['type' => $type->name, 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $check->days], null, null, $r->reference . ' · ' . $user->name);
            $this->audit->finish($op, 1);

            return $r;
        });

        $request = $request->fresh(['user', 'type', 'steps', 'actingOfficer']);
        $this->messenger->notify($user, 'leave', 'Leave application received', "Your {$type->name} application {$request->reference} for {$request->days} day(s) from {$request->start_date->format('j M Y')} has been received.", $request, route('leave.show', $request));
        $this->mailer->leaveLetter($request, 'acknowledgement');
        if ($request->actingOfficer) {
            $this->messenger->notify($request->actingOfficer, 'leave', 'Acting officer request', "{$user->name} has named you acting officer from {$request->start_date->format('j M')} to {$request->end_date->format('j M Y')}. Please accept or decline in the portal.", $request, route('leave.show', $request));
        }
        $this->notifyApprovers($request);

        return $request;
    }

    private function nextReference(int $year): string
    {
        $prefix = 'LV/' . $year . '/';
        $last = LeaveRequest::where('reference', 'like', $prefix . '%')->lockForUpdate()->orderByDesc('id')->value('reference');
        $n = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    // ---- the approval route ---------------------------------------------------------------------------

    public function routeFor(LeaveType $type): Collection
    {
        $steps = LeaveApprovalStep::where('leave_type_id', $type->id)->orderBy('sort_order')->get();

        return $steps->isNotEmpty() ? $steps : LeaveApprovalStep::whereNull('leave_type_id')->orderBy('sort_order')->get();
    }

    /** People who can decide a step (never the applicant). */
    public function approversFor(LeaveRequestStep $step, LeaveRequest $r): Collection
    {
        $profile = $this->profile($r->user);
        $dept = $profile->department;
        $ids = match ($step->approver) {
            'supervisor' => [$profile->supervisor_id],
            'hod' => [$dept?->hod_id],
            'dean' => [$dept?->faculty?->dean_id],
            'role' => $step->role_name ? $this->roleUsers($step->role_name) : [],
            default => [],
        };

        return User::whereIn('id', array_filter($ids))->where('id', '!=', $r->user_id)->where('is_disabled', false)->get();
    }

    private function roleUsers(string $role): array
    {
        try {
            return User::role($role)->pluck('id')->all();
        } catch (\Throwable) {
            return []; // the role does not exist (any more)
        }
    }

    private function buildRoute(LeaveRequest $r): void
    {
        $n = 0;
        foreach ($this->routeFor($r->type) as $s) {
            $step = LeaveRequestStep::create(['leave_request_id' => $r->id, 'step' => ++$n, 'label' => $s->label, 'approver' => $s->approver, 'role_name' => $s->role_name, 'due_days' => $s->due_days, 'status' => 'waiting']);
            $people = $this->approversFor($step, $r);
            if ($people->isEmpty() && $s->can_skip_if_missing) {
                $step->update(['status' => 'skipped', 'comment' => 'Nobody holds this position for the applicant, so the step was skipped.']);
            } else {
                $step->update(['approver_id' => $people->count() === 1 ? $people->first()->id : null]);
            }
        }
        if (! $r->steps()->where('status', 'waiting')->exists()) {
            // Nobody on the route could be found: the leave administrators (HR) decide.
            LeaveRequestStep::create(['leave_request_id' => $r->id, 'step' => ++$n, 'label' => 'Leave administrator', 'approver' => 'admin', 'due_days' => 3, 'status' => 'waiting']);
        }
        $this->advance($r);
    }

    /** Open the next waiting step, or return null when every step is done. */
    private function advance(LeaveRequest $r): ?LeaveRequestStep
    {
        $next = $r->steps()->where('status', 'waiting')->orderBy('step')->first();
        if ($next) {
            $next->update(['status' => 'pending', 'due_at' => now()->addDays(max(1, $next->due_days))]);
            $r->update(['current_step' => $next->step]);
        }

        return $next;
    }

    public function canAct(User $user, LeaveRequest $r): bool
    {
        if ($r->status !== 'pending' || $user->id === $r->user_id) {
            return false;
        }
        $step = $r->steps()->where('status', 'pending')->first();
        if (! $step) {
            return false;
        }

        return $user->can('Approve leave for any staff') || $this->approversFor($step, $r)->contains('id', $user->id);
    }

    public function decide(LeaveRequest $r, User $by, string $action, ?string $comment = null): LeaveRequest
    {
        if (! in_array($action, ['approve', 'reject', 'return'], true)) {
            throw new RuntimeException('Unknown decision.');
        }
        if ($action !== 'approve' && trim((string) $comment) === '') {
            throw new RuntimeException('Give a reason.');
        }

        $r = DB::transaction(function () use ($r, $by, $action, $comment) {
            $r = LeaveRequest::lockForUpdate()->with('user', 'type')->findOrFail($r->id);
            if (! $this->canAct($by, $r)) {
                throw new RuntimeException('This application is not waiting for your decision.');
            }
            $step = $r->steps()->where('status', 'pending')->first();
            $step->update(['status' => ['approve' => 'approved', 'reject' => 'rejected', 'return' => 'returned'][$action], 'acted_by' => $by->id, 'acted_at' => now(), 'comment' => $comment]);

            $final = false;
            if ($action === 'approve') {
                if (! $this->advance($r)) {
                    $r->update(['status' => 'approved', 'decided_at' => now(), 'decided_by' => $by->id, 'decision_note' => $comment]);
                    $final = true;
                }
            } else {
                $r->update(['status' => $action === 'reject' ? 'rejected' : 'returned', 'decided_at' => now(), 'decided_by' => $by->id, 'decision_note' => $comment]);
                $final = true;
            }
            $op = $this->audit->begin('leave_decide', 'leave', $by, null, $comment, ['request' => $r->reference, 'step' => $step->label]);
            $this->audit->record($op, $action === 'approve' ? ($final ? 'approved' : 'approved step') : ($action === 'reject' ? 'rejected' : 'returned'), $r, ['status' => 'pending', 'step' => $step->label], ['status' => $r->fresh()->status], $comment, null, $r->reference . ' · ' . $r->user->name);
            $this->audit->finish($op, 1);

            return $r->fresh(['user', 'type', 'steps', 'actingOfficer']);
        });

        $who = $r->user;
        if ($r->status === 'pending') {
            $this->notifyApprovers($r);
        } elseif ($r->status === 'approved') {
            $this->messenger->notify($who, 'leave', 'Leave approved', "Your {$r->type->name} ({$r->reference}) from {$r->start_date->format('j M Y')} is approved. You are expected back on {$r->expected_resumption->format('l j M Y')}.", $r, route('leave.show', $r), 'success');
            $this->mailer->leaveLetter($r, 'approval');
            $r->actingOfficer && $this->messenger->notify($r->actingOfficer, 'leave', 'You will be acting', "{$who->name}'s leave from {$r->start_date->format('j M')} to {$r->end_date->format('j M Y')} is approved; you are the acting officer.", $r, route('leave.show', $r));
            $this->flagTimetables($r);
        } else {
            $word = $r->status === 'rejected' ? 'not approved' : 'returned for correction';
            $this->messenger->notify($who, 'leave', 'Leave ' . $word, "Your {$r->type->name} application {$r->reference} was {$word}: {$comment}", $r, route('leave.show', $r), $r->status === 'rejected' ? 'danger' : 'warning');
            $this->mailer->leaveLetter($r, $r->status === 'rejected' ? 'rejection' : 'returned');
        }

        return $r;
    }

    private function notifyApprovers(LeaveRequest $r): void
    {
        $step = $r->steps()->where('status', 'pending')->first();
        if (! $step) {
            return;
        }
        $people = $this->approversFor($step, $r);
        if ($people->isEmpty() || $step->approver === 'admin') {
            $people = User::permission('Approve leave for any staff')->where('id', '!=', $r->user_id)->get();
        }
        foreach ($people as $p) {
            $this->messenger->notify($p, 'leave_approvals', 'Leave waiting for your decision', "{$r->user->name}: {$r->type->name}, {$r->days} day(s) from {$r->start_date->format('j M Y')} ({$r->reference}).", $r, route('leave.show', $r), 'info', "lv{$r->id}-s{$step->id}-new-u{$p->id}");
        }
    }

    /** Tell the head of department which classes and invigilation duties fall inside approved leave. */
    private function flagTimetables(LeaveRequest $r): void
    {
        $impact = $this->timetables->staffImpact($r->user, $r->start_date, $r->end_date);
        $n = $impact['teaching']->count() + $impact['duties']->count();
        if (! $n) {
            return;
        }
        $lines = $impact['teaching']->map(fn ($e) => ($e->item?->course?->code ?? 'Class') . ' ' . $e->when)->merge($impact['duties']->map(fn ($d) => 'Invigilation ' . $d->date->format('D j M') . ' ' . substr($d->starts_at, 0, 5) . ' ' . $d->venue?->name))->take(8)->implode('; ');
        $r->update(['warnings' => array_values(array_unique(array_merge($r->warnings ?? [], ["Timetable: {$n} class(es) or duty(ies) fall in this leave: {$lines}"])))]);
        $hod = $this->profile($r->user)->department?->hod;
        foreach (collect([$hod])->filter()->where('id', '!=', $r->user_id) as $u) {
            $this->messenger->notify($u, 'leave', 'Cover needed while on leave', "{$r->user->name} is on approved leave {$r->start_date->format('j M')}–{$r->end_date->format('j M')}. Arrange cover for: {$lines}", $r, route('leave.show', $r), 'warning');
        }
    }

    public function respondAsActing(LeaveRequest $r, User $by, bool $accept): void
    {
        if ($r->acting_officer_id !== $by->id || $r->acting_status !== 'pending') {
            throw new RuntimeException('There is no acting request waiting for you here.');
        }
        $r->update(['acting_status' => $accept ? 'accepted' : 'declined']);
        $this->messenger->notify($r->user, 'leave', $accept ? 'Acting officer accepted' : 'Acting officer declined', "{$by->name} has " . ($accept ? 'accepted' : 'declined') . " to act for you during {$r->reference}.", $r, route('leave.show', $r), $accept ? 'success' : 'warning');
    }

    public function cancel(LeaveRequest $r, User $by, string $reason): void
    {
        $own = $by->id === $r->user_id;
        if (! $own && ! $by->can('Approve leave for any staff')) {
            throw new RuntimeException('You cannot cancel this application.');
        }
        if (! in_array($r->status, ['pending', 'returned', 'approved'], true) || ($r->status === 'approved' && ! now()->startOfDay()->lt($r->start_date))) {
            throw new RuntimeException('Leave that has started cannot be cancelled. Record the resumption date instead; unused days are given back.');
        }
        DB::transaction(function () use ($r, $by, $reason) {
            $before = $r->status;
            $r->update(['status' => 'cancelled', 'decision_note' => $reason, 'decided_at' => now(), 'decided_by' => $by->id]);
            $r->steps()->whereIn('status', ['pending', 'waiting'])->update(['status' => 'skipped', 'comment' => 'Application cancelled']);
            $this->audit->note('leave_cancel', 'leave', $by, 'cancelled', $r, ['status' => $before], ['status' => 'cancelled'], $reason);
        });
        $this->messenger->notify($r->user, 'leave', 'Leave cancelled', "{$r->reference} has been cancelled" . ($by->id === $r->user_id ? '.' : " by {$by->name}: {$reason}"), $r, route('leave.show', $r), 'warning');
        $this->mailer->leaveLetter($r->fresh(['user', 'type']), 'cancelled');
    }

    // ---- resumption -----------------------------------------------------------------------------------

    public function recordResumption(LeaveRequest $r, Carbon $on, User $by, ?string $note = null): LeaveRequest
    {
        if ($r->status !== 'approved') {
            throw new RuntimeException('Only approved leave has a resumption.');
        }
        if ($on->lt($r->start_date)) {
            throw new RuntimeException('Resumption cannot be before the leave started. Cancel it instead.');
        }
        if ($by->id === $r->user_id && $on->gt(now()->endOfDay())) {
            throw new RuntimeException('You can only confirm a resumption on or after the day you are back.');
        }
        $late = $on->gt($r->expected_resumption) ? self::workingDaysBetween($r->expected_resumption, $on->copy()->subDay()) : 0;
        $used = $on->lt($r->expected_resumption) ? $this->countDays($r->type, $r->start_date, $on->copy()->subDay()) : null;

        DB::transaction(function () use ($r, $on, $by, $note, $late, $used) {
            $r->update(['resumed_on' => $on, 'resumption_recorded_by' => $by->id, 'resumption_note' => $note, 'late_days' => $late, 'days_used' => $used]);
            $this->audit->note('leave_resume', 'leave', $by, 'resumed', $r, ['expected' => $r->expected_resumption->toDateString()], ['resumed' => $on->toDateString(), 'late_days' => $late, 'days_used' => $used ?? $r->days], $note);
        });
        $r = $r->fresh(['user', 'type']);
        $this->mailer->leaveLetter($r, 'resumption');
        if ($late) {
            foreach (User::permission('Record staff resumption')->get()->push($this->profile($r->user)->department?->hod)->filter()->unique('id') as $u) {
                $this->messenger->notify($u, 'leave', 'Late resumption', "{$r->user->name} resumed on {$on->format('j M Y')}, {$late} working day(s) after the expected date ({$r->reference}).", $r, route('leave.show', $r), 'warning', null, ['portal']);
            }
        }

        return $r;
    }

    // ---- balances -------------------------------------------------------------------------------------

    public function adjust(User $user, LeaveType $type, int $year, float $days, string $note, User $by): void
    {
        DB::transaction(function () use ($user, $type, $year, $days, $note, $by) {
            $before = $this->balance($user, $type, $year)->available();
            LeaveLedgerEntry::create(['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $year, 'kind' => 'adjustment', 'days' => $days, 'note' => $note, 'created_by' => $by->id]);
            $this->audit->note('leave_adjust', 'leave', $by, 'balance adjusted', $user, ['available' => $before], ['available' => $before + $days, 'type' => $type->name, 'year' => $year], $note);
        });
    }

    /** Carry unused days (up to each type's limit) from one leave year into the next. Safe to repeat. */
    public function carryOver(int $from, User $by): int
    {
        $n = 0;
        DB::transaction(function () use ($from, $by, &$n) {
            $op = $this->audit->begin('leave_carryover', 'leave', $by, null, null, ['from' => $from, 'to' => $from + 1]);
            $types = LeaveType::with('entitlements')->where('max_carryover', '>', 0)->get();
            $users = User::whereDoesntHave('student')->where('is_disabled', false)->get();
            foreach ($types as $type) {
                foreach ($users as $user) {
                    if (LeaveLedgerEntry::where(['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $from + 1, 'kind' => 'carryover'])->exists()) {
                        continue;
                    }
                    $left = min($type->max_carryover, max(0, $this->balance($user, $type, $from)->available()));
                    if ($left > 0) {
                        LeaveLedgerEntry::create(['user_id' => $user->id, 'leave_type_id' => $type->id, 'year' => $from + 1, 'kind' => 'carryover', 'days' => $left, 'note' => "Carried over from {$from}", 'created_by' => $by->id]);
                        $this->audit->record($op, 'carried over', $user, null, ['type' => $type->name, 'days' => $left]);
                        $n++;
                    }
                }
            }
            $this->audit->finish($op, $n);
        });

        return $n;
    }

    // ---- daily reminders and escalation ---------------------------------------------------------------

    /** Run once a day (scheduled). Each reminder is sent once; returns how many went out. */
    public function daily(?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $sent = ['approvals' => 0, 'escalated' => 0, 'starting' => 0, 'remaining' => 0, 'late' => 0];

        foreach (LeaveRequestStep::with('request.user', 'request.type')->where('status', 'pending')->whereNotNull('due_at')->where('due_at', '<', $today->copy()->endOfDay())->get() as $step) {
            $r = $step->request;
            if (! $step->reminded_at) {
                foreach ($this->approversFor($step, $r)->whenEmpty(fn () => User::permission('Approve leave for any staff')->get()) as $u) {
                    $this->messenger->notify($u, 'leave_approvals', 'Reminder: leave waiting for you', "{$r->user->name}'s {$r->type->name} ({$r->reference}) has waited since {$step->updated_at->format('j M')}.", $r, route('leave.show', $r), 'warning', "lv{$r->id}-s{$step->id}-remind-u{$u->id}");
                }
                $step->update(['reminded_at' => now()]);
                $sent['approvals']++;
            } elseif (! $step->escalated_at && $step->due_at->copy()->addDays(max(1, $step->due_days))->lt($today->copy()->endOfDay())) {
                foreach (User::permission('Approve leave for any staff')->where('id', '!=', $r->user_id)->get() as $u) {
                    $this->messenger->notify($u, 'leave_approvals', 'Escalated: leave decision overdue', "{$r->reference} ({$r->user->name}) is overdue at \"{$step->label}\". You can decide it as leave administrator.", $r, route('leave.show', $r), 'danger', "lv{$r->id}-s{$step->id}-esc-u{$u->id}");
                }
                $step->update(['escalated_at' => now()]);
                $sent['escalated']++;
            }
        }

        $before = (int) self::setting('remind_before');
        $left = array_filter(array_map('intval', explode(',', self::setting('remind_left'))));
        $grace = (int) self::setting('late_grace');
        foreach (LeaveRequest::with('user', 'type', 'actingOfficer')->where('status', 'approved')->whereNull('resumed_on')->whereDate('start_date', '<=', $today->copy()->addDays($before))->get() as $r) {
            $done = $r->reminders ?? [];
            if ($today->lt($r->start_date)) {
                if (! in_array('starting', $done, true)) {
                    $days = (int) $today->diffInDays($r->start_date);
                    $this->messenger->notify($r->user, 'leave', 'Your leave starts soon', "Your {$r->type->name} starts in {$days} day(s), on {$r->start_date->format('l j M')}. Please hand over to " . ($r->actingOfficer?->name ?? 'your head of department') . '.', $r, route('leave.show', $r), 'info', "lv{$r->id}-starting");
                    $r->actingOfficer && $this->messenger->notify($r->actingOfficer, 'leave', 'Acting duty starts soon', "You act for {$r->user->name} from {$r->start_date->format('l j M')}.", $r, route('leave.show', $r), 'info', "lv{$r->id}-acting");
                    $done[] = 'starting';
                    $sent['starting']++;
                }
            } elseif ($today->lt($r->expected_resumption)) {
                $remaining = self::workingDaysBetween($today, $r->expected_resumption->copy()->subDay());
                if (in_array($remaining, $left, true) && ! in_array("left{$remaining}", $done, true)) {
                    $this->messenger->notify($r->user, 'leave', "{$remaining} working day(s) of leave left", "You are expected back on {$r->expected_resumption->format('l j M Y')}.", $r, route('leave.show', $r), 'info', "lv{$r->id}-left{$remaining}");
                    $done[] = "left{$remaining}";
                    $sent['remaining']++;
                }
            } elseif (! $r->late_flagged_at && self::workingDaysBetween($r->expected_resumption, $today) > $grace) {
                $r->late_flagged_at = now();
                $people = User::permission('Record staff resumption')->get()->push($this->profile($r->user)->department?->hod)->filter()->unique('id');
                foreach ($people as $u) {
                    $this->messenger->notify($u, 'leave', 'Resumption overdue', "{$r->user->name} was due back on {$r->expected_resumption->format('j M Y')} ({$r->reference}) and no resumption has been recorded.", $r, route('leave.show', $r), 'danger', "lv{$r->id}-late-u{$u->id}");
                }
                $this->messenger->notify($r->user, 'leave', 'Please confirm your resumption', "You were due back on {$r->expected_resumption->format('j M Y')}. Confirm your resumption in the portal.", $r, route('leave.show', $r), 'warning', "lv{$r->id}-late-self");
                $sent['late']++;
            }
            $r->reminders = $done;
            $r->save();
        }

        return $sent;
    }

    // ---- who may see what -----------------------------------------------------------------------------

    public function canView(User $user, LeaveRequest $r): bool
    {
        return $user->id === $r->user_id || $user->id === $r->acting_officer_id || $user->can('View leave applications')
            || $user->can('Approve leave for any staff') || $r->steps->contains(fn ($s) => $s->acted_by === $user->id)
            || $r->steps->contains(fn ($s) => in_array($s->status, ['pending', 'waiting'], true) && $this->approversFor($s, $r)->contains('id', $user->id));
    }

    public function canOpenDocument(User $user, LeaveDocument $doc): bool
    {
        $r = $doc->request;
        if ($user->id === $r->user_id) {
            return true;
        }

        return $doc->confidential ? $user->can('View confidential leave documents') : $this->canView($user, $r->loadMissing('steps'));
    }

    /** Applications waiting for this user's decision. */
    public function waitingFor(User $user): Collection
    {
        return LeaveRequest::with('user', 'type', 'steps')->where('status', 'pending')->where('user_id', '!=', $user->id)->latest('submitted_at')->get()
            ->filter(fn ($r) => $this->canAct($user, $r))->values();
    }
}
