<?php

namespace App\Services;

use App\Models\ClearanceItem;
use App\Models\MatricEvent;
use App\Models\Student;
use App\Models\StudentClearance;
use App\Models\User;
use App\Notifications\ClearanceUpdated;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Clearance: the checks a newly admitted student must pass before a matric number is issued.
 * Each university chooses its items. Automatic items clear themselves from what the system already knows;
 * manual items are signed off by the office that owns them.
 */
class ClearanceService
{
    public function __construct(private OnboardingService $onboarding, private MatricNumberGenerator $matric)
    {
    }

    /** @return Collection<int, ClearanceItem> */
    public function items(): Collection
    {
        return ClearanceItem::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Does the student currently pass this automatic check? */
    public function passes(Student $student, string $rule): bool
    {
        $student->loadMissing('applicant.documents');

        return match ($rule) {
            'offer_accepted'      => $student->offer_accepted_at !== null,
            'acceptance_fee_paid' => ! ApplicationSettings::acceptanceItemId() || ($this->onboarding->acceptanceInvoice($student)?->status === 'paid'),
            'biodata_complete'    => $this->onboarding->biodataComplete($student),
            'documents_uploaded'  => collect($this->onboarding->requiredDocuments($student))->keys()->every(fn ($t) => $this->onboarding->documents($student)->has($t)),
            'verified'            => $student->verified_at !== null,
            'fees_paid'           => $this->feesPaid($student),
            default               => false,
        };
    }

    private function feesPaid(Student $student): bool
    {
        $invoices = $student->invoices()->where('kind', 'fees')->where('academic_session_id', $student->academic_session_id)->where('status', '!=', 'cancelled')->get();

        return $invoices->isNotEmpty() && $invoices->every(fn ($i) => $i->status === 'paid');
    }

    /**
     * Bring a student's clearance up to date: create missing rows, let automatic items clear (or un-clear) themselves,
     * work out whether they are fully cleared and, if the university issues matric numbers on clearance, issue it.
     * Safe to call as often as you like.
     */
    public function refresh(Student $student): void
    {
        if (! $student->needs_onboarding) {
            return; // imported / legacy students do not go through clearance
        }

        $items = $this->items();
        $rows = $student->clearances()->get()->keyBy('clearance_item_id');

        foreach ($items as $item) {
            $row = $rows->get($item->id) ?? StudentClearance::create(['student_id' => $student->id, 'clearance_item_id' => $item->id]);

            if ($item->isAuto()) {
                $passes = $this->passes($student, $item->auto_rule);
                if ($passes && $row->status !== 'cleared') {
                    $row->update(['status' => 'cleared', 'note' => 'Checked automatically.', 'decided_by' => null, 'decided_at' => now()]);
                } elseif (! $passes && $row->status === 'cleared') {
                    $row->update(['status' => 'pending', 'note' => null, 'decided_at' => null]);
                }
            }
        }

        $cleared = $this->isCleared($student);
        if ($cleared && ! $student->cleared_at) {
            $student->update(['cleared_at' => now()]);
            $this->notify($student, 'You are cleared', 'All your clearance items are cleared.' . (MatricNumberGenerator::issueAt() === 'clearance' ? ' Your matric number will be issued shortly.' : ''), 'success');
        } elseif (! $cleared && $student->cleared_at) {
            $student->update(['cleared_at' => null]);
        }

        if ($cleared && ! $student->matric_number && MatricNumberGenerator::issueAt() === 'clearance' && MatricNumberGenerator::autoIssue()) {
            $this->issueMatric($student);
        }
    }

    /** Every required, active item is cleared (and there is at least one item to clear). */
    public function isCleared(Student $student): bool
    {
        $required = $this->items()->where('is_required', true);
        if ($required->isEmpty()) {
            return false;
        }
        $cleared = $student->clearances()->where('status', 'cleared')->pluck('clearance_item_id');

        return $required->every(fn ($i) => $cleared->contains($i->id));
    }

    /** @return Collection<int, StudentClearance> the student's rows for the active items, in order */
    public function checklist(Student $student): Collection
    {
        $this->refresh($student);
        $ids = $this->items()->pluck('id');

        return $student->clearances()->with('item', 'decider')->whereIn('clearance_item_id', $ids)->get()
            ->sortBy(fn ($r) => [$r->item->sort_order, $r->item->id])->values();
    }

    /** May this staff member sign off this item? */
    public function canSign(User $user, ClearanceItem $item): bool
    {
        if ($item->isAuto() || ! $user->can('Clear students')) {
            return false;
        }

        return ! $item->office_role || $user->hasRole($item->office_role) || $user->can('Clear students for any office');
    }

    public function decide(Student $student, ClearanceItem $item, string $status, ?string $note, User $by): StudentClearance
    {
        if (! in_array($status, ['cleared', 'rejected'], true)) {
            throw new RuntimeException('A decision is either cleared or rejected.');
        }
        if ($item->isAuto()) {
            throw new RuntimeException("\"{$item->name}\" clears itself automatically and cannot be signed off by hand.");
        }
        if (! $this->canSign($by, $item)) {
            throw new RuntimeException("You are not allowed to sign off \"{$item->name}\".");
        }
        if ($status === 'rejected' && blank($note)) {
            throw new RuntimeException('Say why you are rejecting it so the student knows what to fix.');
        }
        if (! $student->needs_onboarding) {
            throw new RuntimeException('This student does not go through clearance.');
        }

        $this->refresh($student);

        $row = StudentClearance::where('student_id', $student->id)->where('clearance_item_id', $item->id)->firstOrFail();
        $row->update(['status' => $status, 'note' => $note, 'decided_by' => $by->id, 'decided_at' => now()]);

        $this->notify(
            $student,
            $status === 'cleared' ? "{$item->name}: cleared" : "{$item->name}: not cleared",
            $status === 'cleared' ? "{$item->name} has been cleared." : "{$item->name} was rejected: {$note}. Fix this and re-submit it from your clearance page.",
            $status === 'cleared' ? 'success' : 'danger',
        );

        $this->refresh($student->fresh());

        return $row->fresh();
    }

    /** The student fixed what was rejected and asks for another look. */
    public function resubmit(Student $student, ClearanceItem $item, ?string $note): void
    {
        $row = StudentClearance::where('student_id', $student->id)->where('clearance_item_id', $item->id)->first();

        if (! $row || $row->status !== 'rejected') {
            throw new RuntimeException('Only a rejected item can be re-submitted.');
        }
        if ($item->isAuto()) {
            throw new RuntimeException('This item is checked automatically. Complete it and it will clear by itself.');
        }

        $row->update(['status' => 'pending', 'student_note' => $note, 'resubmitted_at' => now()]);
    }

    /** Reverse a manual decision, putting the item back to pending. */
    public function reset(Student $student, ClearanceItem $item, User $by): void
    {
        if (! $this->canSign($by, $item)) {
            throw new RuntimeException("You are not allowed to change \"{$item->name}\".");
        }
        StudentClearance::where('student_id', $student->id)->where('clearance_item_id', $item->id)
            ->update(['status' => 'pending', 'note' => null, 'decided_by' => null, 'decided_at' => null]);

        $this->refresh($student->fresh());
    }

    /** Give a cleared student their matric number. Returns it. */
    public function issueMatric(Student $student, ?User $by = null, string $action = 'generated', ?string $batch = null): string
    {
        return DB::transaction(function () use ($student, $by, $action, $batch) {
            $student = Student::lockForUpdate()->findOrFail($student->id);
            if ($student->matric_number) {
                return $student->matric_number;
            }

            $student->loadMissing('programme.department.faculty', 'entrySession');
            $matric = $this->matric->next($student->programme, $student->entrySession);

            $this->assignMatric($student, $matric);
            MatricEvent::create(['student_id' => $student->id, 'action' => $action, 'matric_number' => $matric, 'batch' => $batch, 'performed_by' => $by?->id]);
            $this->notify($student, 'Your matric number', "Your matric number is {$matric}. You can now sign in with it.", 'success');

            return $matric;
        });
    }

    /** Store a matric number on the student and make it their login name. */
    public function assignMatric(Student $student, ?string $matric): void
    {
        $student->update(['matric_number' => $matric]);
        $student->user?->update(['username' => $matric ?: ($student->applicant?->application_no ?? $student->user->username)]);
    }

    public function notify(Student $student, string $title, string $body, string $level = 'info'): void
    {
        $student->user?->notify(new ClearanceUpdated($title, $body, $level));
    }
}
