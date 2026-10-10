<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\CourseResult;
use App\Models\GradeBand;
use App\Models\ResultSheet;
use App\Models\Semester;
use App\Models\SessionStateChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** A session moves Draft -> Setup -> Active -> Closing -> Closed -> Archived, and is checked before it goes live. */
class SessionLifecycle
{
    public const STATUSES = [
        'draft'    => 'Draft',
        'setup'    => 'Setup',
        'active'   => 'Active',
        'closing'  => 'Closing',
        'closed'   => 'Closed',
        'archived' => 'Archived',
    ];

    public const BADGES = ['draft' => 'secondary', 'setup' => 'info', 'active' => 'success', 'closing' => 'warning', 'closed' => 'dark', 'archived' => 'light text-dark'];

    private const ALLOWED = [
        'draft'    => ['setup', 'active'],
        'setup'    => ['draft', 'active'],
        'active'   => ['closing'],
        'closing'  => ['active', 'closed'],
        'closed'   => ['active', 'archived'],
        'archived' => [],
    ];

    public function __construct(private OperationAudit $audit)
    {
    }

    /** @return string[] statuses this session can move to right now */
    public function next(AcademicSession $session): array
    {
        $out = self::ALLOWED[$session->status] ?? [];
        if ($session->status === 'closed' && ! SessionSettings::on('allow_reopen')) {
            $out = array_values(array_diff($out, ['active']));
        }

        return $out;
    }

    /** Whether a reason must be given for this move. */
    public function needsReason(string $from, string $to): bool
    {
        return in_array($to, ['closing', 'closed', 'archived'], true) || ($to === 'active' && in_array($from, ['closing', 'closed'], true)) || ($to === 'draft');
    }

    /**
     * What stops (problems) or deserves a look (warnings) before a session moves to the given status.
     *
     * @return array{problems: string[], warnings: string[]}
     */
    public function check(AcademicSession $session, string $to): array
    {
        $problems = [];
        $warnings = [];

        if ($to === 'active') {
            $semesters = $session->semesters()->get();
            if (SessionSettings::on('require_semesters') && $semesters->isEmpty()) {
                $problems[] = 'Add at least one semester to this session first.';
            }
            if ($session->start_date && $session->end_date && $session->end_date->lte($session->start_date)) {
                $problems[] = 'The session ends before it starts.';
            }
            foreach ($semesters as $s) {
                if ($s->start_date && $s->end_date && $s->end_date->lte($s->start_date)) {
                    $problems[] = "{$s->name} ends before it starts.";
                }
                if ($session->start_date && $s->start_date && $s->start_date->lt($session->start_date)
                    || $session->end_date && $s->end_date && $s->end_date->gt($session->end_date)) {
                    $problems[] = "{$s->name} falls outside the session dates.";
                }
                if ($s->registration_opens_at && $s->registration_closes_at && $s->registration_closes_at->lte($s->registration_opens_at)) {
                    $problems[] = "{$s->name}: registration closes before it opens.";
                }
            }
            if (! GradeBand::query()->exists()) {
                $problems[] = 'No grading scale has been set up.';
            }
            if (! SessionSettings::on('allow_multiple_active')) {
                $other = AcademicSession::where('status', 'active')->where('id', '!=', $session->id)->pluck('name');
                if ($other->isNotEmpty()) {
                    $problems[] = 'Close ' . $other->implode(', ') . ' first: only one session can be active at a time.';
                }
            }
            if ($semesters->isNotEmpty() && $semesters->every(fn ($s) => ! $s->registration_opens_at)) {
                $warnings[] = 'No semester has a registration window, so registration will be open all semester.';
            }
        }

        if (in_array($to, ['closing', 'closed'], true)) {
            $pending = ResultSheet::where('academic_session_id', $session->id)->whereIn('status', ['draft', 'submitted', 'approved'])->count();
            if ($pending) {
                $warnings[] = "{$pending} score sheet(s) for this session are not published yet.";
            }
        }

        return ['problems' => $problems, 'warnings' => $warnings];
    }

    public function transition(AcademicSession $session, string $to, User $by, ?string $reason = null): AcademicSession
    {
        $from = $session->status;
        if (! array_key_exists($to, self::STATUSES)) {
            throw new RuntimeException('That is not a session status.');
        }
        if (! in_array($to, $this->next($session), true)) {
            throw new RuntimeException("A {$this->label($from)} session cannot move to {$this->label($to)}.");
        }
        $reason = trim((string) $reason);
        if ($this->needsReason($from, $to) && $reason === '') {
            throw new RuntimeException('Give a reason for this change.');
        }
        $check = $this->check($session, $to);
        if ($check['problems']) {
            throw new RuntimeException(implode(' ', $check['problems']));
        }

        DB::transaction(function () use ($session, $from, $to, $by, $reason) {
            $session->update(['status' => $to]);
            if (in_array($to, ['closed', 'archived', 'draft', 'setup'], true) && $session->is_current) {
                $session->update(['is_current' => false]);
                Semester::where('academic_session_id', $session->id)->update(['is_current' => false]);
            }
            SessionStateChange::create(['academic_session_id' => $session->id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $by->id, 'reason' => $reason ?: null]);
            $this->audit->note('session_status', 'sessions', $by, 'status_changed', $session, ['status' => $from], ['status' => $to], $reason ?: null, $session->id);
        });

        return $session->fresh();
    }

    /** Make a session the current one, activating it first when it is still in Draft or Setup. */
    public function makeCurrent(AcademicSession $session, User $by): void
    {
        if (in_array($session->status, ['closed', 'archived'], true)) {
            throw new RuntimeException("{$session->name} is {$this->label($session->status)} and cannot be the current session. Reopen it first.");
        }
        if (in_array($session->status, ['draft', 'setup'], true)) {
            // Handing over: a session that was active moves to Closing (not closed) so its results can be finished.
            // All or nothing: if this session cannot go active, the previous one stays as it was.
            $session = DB::transaction(function () use ($session, $by) {
                if (! SessionSettings::on('allow_multiple_active')) {
                    foreach (AcademicSession::where('status', 'active')->where('id', '!=', $session->id)->get() as $previous) {
                        $this->transition($previous, 'closing', $by, "Replaced by {$session->name} as the current session");
                    }
                }

                return $this->transition($session, 'active', $by, null);
            });
        }
        $session->makeCurrent();
    }

    public function label(string $status): string
    {
        return self::STATUSES[$status] ?? ucfirst($status);
    }
}
