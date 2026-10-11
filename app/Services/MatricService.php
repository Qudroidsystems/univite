<?php

namespace App\Services;

use App\Data\Matric\MatricImportResult;
use App\Data\Matric\MatricPreview;
use App\Data\Matric\MatricRun;
use App\Data\Matric\MatricSelection;
use App\Models\MatricEvent;
use App\Models\OperationBatch;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** The life of a matric number after clearance: generate in a batch, set a special one, bring in external ones, cancel, restore, undo. */
class MatricService
{
    public function __construct(
        private ClearanceService $clearance,
        private MatricNumberGenerator $generator,
        private MatricApproval $approval,
        private OperationAudit $audit,
        private DocumentMailer $mailer,
        private \App\Reversals\MatricBatchReversal $reversal,
    ) {
    }

    /** Cleared students still waiting for a number, in the order numbers will be handed out. */
    public function waiting(): \Illuminate\Database\Eloquent\Builder
    {
        return Student::query()->whereNotNull('cleared_at')->whereNull('matric_number')->whereNull('provisional_matric')
            ->whereDoesntHave('matricEvents', fn ($q) => $q->where('action', 'cancelled')->whereRaw('matric_events.id = (select max(id) from matric_events e2 where e2.student_id = matric_events.student_id)'));
    }

    /** Students whose number was cancelled and not yet restored or replaced. */
    public function cancelled(): \Illuminate\Database\Eloquent\Builder
    {
        return Student::query()->whereNull('matric_number')
            ->whereHas('matricEvents', fn ($q) => $q->where('action', 'cancelled')->whereRaw('matric_events.id = (select max(id) from matric_events e2 where e2.student_id = matric_events.student_id)'));
    }

    /**
     * Give numbers to waiting students, alphabetically by surname so the register reads in order.
     *
     * Ticked students must be waiting; with none ticked, every waiting student (in the programme, if one is chosen) is numbered.
     */
    public function generateBatch(MatricSelection $selection, User $by): MatricRun
    {
        $approval = MatricNumberGenerator::requiresApproval();
        $batch = $approval ? $this->approval->newBatch($by)->code : strtoupper(Str::random(8));
        $issued = 0;
        $audit = $this->audit;
        $op = $audit->begin($approval ? 'matric_propose' : 'matric_generate', 'matric', $by, null, null, ['batch' => $batch, 'programme_id' => $selection->programmeId]);

        $this->selected($selection)
            ->orderBy('programme_id')->orderBy('academic_session_id')->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->each(function (Student $student) use ($by, $batch, &$issued, $audit, $op, $approval) {
                $this->clearance->issueMatric($student, $by, 'generated', $batch);
                $student = $student->fresh();
                $approval
                    ? $audit->record($op, 'matric_reserved', $student, ['provisional' => null], ['provisional' => $student->provisional_matric])
                    : $audit->record($op, 'matric_issued', $student, ['matric_number' => null], ['matric_number' => $student->matric_number]);
                $issued++;
            });

        if (! $issued) {
            $audit->finish($op, 0, 0, 0);
            $op->update(['status' => 'failed']);
            \App\Models\MatricBatch::where('code', $batch)->where('status', 'pending')->delete();
            throw new RuntimeException('There are no cleared students waiting for a matric number.');
        }
        $audit->finish($op, $issued);

        return new MatricRun($batch, $issued, $approval);
    }

    /** Set a number by hand for one cleared student, for example a special or reserved number. */
    public function assignSpecial(Student $student, string $matric, User $by, ?string $note = null, string $action = 'special'): void
    {
        $matric = trim($matric);
        $this->guardAssignable($student, $matric);

        DB::transaction(function () use ($student, $matric, $by, $note, $action) {
            $this->clearance->assignMatric($student, $matric);
            MatricEvent::create(['student_id' => $student->id, 'action' => $action, 'matric_number' => $matric, 'performed_by' => $by->id, 'note' => $note]);
        });

        $this->clearance->notify($student->fresh(), 'Your matric number', "Your matric number is {$matric}. You can now sign in with it.", 'success');
        if ($action === 'special') {
            $this->audit->note('matric_special', 'matric', $by, 'matric_assigned', $student->fresh(), ['matric_number' => null], ['matric_number' => $matric], $note);
        }
        $this->mailer->matricNumber($student->fresh(), 'issued', null, null, $by);
    }

    private function guardAssignable(Student $student, string $matric): void
    {
        if ($matric === '' || strlen($matric) > 60 || preg_match('/[\s\\\\]/', $matric)) {
            throw new RuntimeException('A matric number cannot be blank, contain spaces or be longer than 60 characters.');
        }
        if (! $student->cleared_at) {
            throw new RuntimeException("{$student->full_name} is not fully cleared yet.");
        }
        if ($student->matric_number) {
            throw new RuntimeException("{$student->full_name} already has the matric number {$student->matric_number}. Cancel it first to give a different one.");
        }
        if ($this->generator->taken($matric)) {
            throw new RuntimeException("{$matric} is already taken or was cancelled earlier.");
        }
    }

    /**
     * Apply numbers made elsewhere. Each row is [identifier, matric number]; the identifier is an application number,
     * JAMB number or email. Rows that cannot be applied are reported, never silently dropped.
     *
     * @param iterable<array{0: string, 1: string}> $rows
     * @return array{applied: int, skipped: array<int, string>}
     */
    public function importExternal(iterable $rows, User $by): MatricImportResult
    {
        $applied = 0;
        $skipped = [];
        $batch = strtoupper(Str::random(8));
        $audit = $this->audit;
        $op = $audit->begin('matric_import', 'matric', $by, null, null, ['batch' => $batch]);

        foreach ($rows as $i => [$identifier, $matric]) {
            $identifier = trim((string) $identifier);
            $matric = trim((string) $matric);
            $line = "Row " . ($i + 1) . " ({$identifier})";

            $student = Student::where('needs_onboarding', true)->where(function ($q) use ($identifier) {
                $q->whereHas('applicant', fn ($a) => $a->where('application_no', $identifier)->orWhere('jamb_reg_no', strtoupper($identifier))->orWhere('email', strtolower($identifier)))
                    ->orWhereHas('user', fn ($u) => $u->where('email', strtolower($identifier)));
            })->first();

            if (! $student) {
                $skipped[] = "{$line}: no student found with that application number, JAMB number or email.";
                continue;
            }

            try {
                $this->assignSpecial($student, $matric, $by, "Imported (batch {$batch})", 'external');
                MatricEvent::where('student_id', $student->id)->latest('id')->first()?->update(['batch' => $batch]);
                $audit->record($op, 'matric_applied', $student->fresh(), ['matric_number' => null], ['matric_number' => $matric]);
                $applied++;
            } catch (RuntimeException $e) {
                $skipped[] = "{$line}: " . $e->getMessage();
            }
        }

        $audit->finish($op, $applied, 0, count($skipped));
        if (! $applied) {
            $op->update(['status' => 'failed']);
        }

        return new MatricImportResult($applied, $skipped, $batch);
    }

    /** Withdraw a number. It is kept on record and never handed out again. */
    public function cancel(Student $student, string $reason, User $by): void
    {
        if (! $student->matric_number) {
            throw new RuntimeException('This student has no matric number to cancel.');
        }
        if (blank($reason)) {
            throw new RuntimeException('Give a reason for cancelling the number.');
        }

        $number = $student->matric_number;
        DB::transaction(function () use ($student, $number, $reason, $by) {
            MatricEvent::create(['student_id' => $student->id, 'action' => 'cancelled', 'matric_number' => $number, 'performed_by' => $by->id, 'note' => $reason]);
            $this->clearance->assignMatric($student, null);
            $this->audit->note('matric_cancel', 'matric', $by, 'matric_cancelled', $student, ['matric_number' => $number], ['matric_number' => null], $reason);
        });
    }

    /** Give a cancelled number back to the student it was cancelled from. */
    public function restore(Student $student, User $by): string
    {
        $last = MatricEvent::where('student_id', $student->id)->latest('id')->first();
        if ($student->matric_number || ! $last || $last->action !== 'cancelled') {
            throw new RuntimeException('There is no cancelled matric number to restore for this student.');
        }
        if (Student::where('matric_number', $last->matric_number)->exists()) {
            throw new RuntimeException("{$last->matric_number} now belongs to another student.");
        }

        DB::transaction(function () use ($student, $last, $by) {
            $this->clearance->assignMatric($student, $last->matric_number);
            MatricEvent::create(['student_id' => $student->id, 'action' => 'restored', 'matric_number' => $last->matric_number, 'performed_by' => $by->id]);
            $this->audit->note('matric_restore', 'matric', $by, 'matric_restored', $student->fresh(), ['matric_number' => null], ['matric_number' => $last->matric_number]);
        });

        return $last->matric_number;
    }

    /** The logged operation of a generate / import batch, if it was made after the operation log existed. */
    public function operationFor(string $batch): ?OperationBatch
    {
        return OperationBatch::whereIn('type', ['matric_generate', 'matric_import'])->where('meta->batch', $batch)->latest('id')->first();
    }

    /**
     * Take back every number of a batch that nobody has used yet, so it can be issued again. Numbers already on a course
     * form, result, receipt or emailed document are left alone (correct them from the operation history instead).
     */
    public function undoBatch(string $batch, User $by, string $reason = 'Batch undone from the matric page.'): int
    {
        if ($op = $this->operationFor($batch)) {
            $handler = $this->reversal;
            $plan = $handler->plan($op, 'take_back');
            if (! $plan->canRun()) {
                $why = collect($plan->items)->where('outcome', '!=', 'change')->pluck('why')->unique()->take(2)->implode(' ');
                throw new RuntimeException('None of that batch\'s numbers can be taken back. ' . ($plan->blockers[0] ?? $why));
            }
            $handler->run($op, $plan, $by, $reason);

            return $plan->counts()['change'];
        }

        // Batches from before the operation log: take back what the students still hold.
        $events = MatricEvent::where('batch', $batch)->whereIn('action', ['generated', 'external'])->get();
        if ($events->isEmpty()) {
            throw new RuntimeException('There is no batch with that code.');
        }
        $undone = 0;
        $audit = $this->audit;
        $op = $audit->begin('matric_undo', 'matric', $by, null, $reason, ['batch' => $batch]);
        DB::transaction(function () use ($events, $batch, $by, &$undone, $audit, $op, $reason) {
            foreach ($events as $event) {
                $student = Student::find($event->student_id);
                if (! $student || $student->matric_number !== $event->matric_number) {
                    continue;
                }
                $this->clearance->assignMatric($student, null);
                MatricEvent::create(['student_id' => $student->id, 'action' => 'undone', 'matric_number' => $event->matric_number, 'batch' => $batch, 'performed_by' => $by->id, 'note' => $reason]);
                $this->generator->rewind($student);
                $audit->record($op, 'matric_taken_back', $student, ['matric_number' => $event->matric_number], ['matric_number' => null]);
                $undone++;
            }
        });
        $audit->finish($op, $undone, 0, $events->count() - $undone);
        if (! $undone) {
            throw new RuntimeException('None of that batch\'s numbers could be undone: they have all been changed or cancelled since.');
        }

        return $undone;
    }

    /**
     * What generating would do, without issuing anything: who is waiting and the number each would get.
     *
     */
    public function previewBatch(MatricSelection $selection): MatricPreview
    {
        $students = $this->selected($selection)
            ->with('programme.department.faculty', 'entrySession')
            ->orderBy('programme_id')->orderBy('academic_session_id')->orderBy('last_name')->orderBy('first_name')->orderBy('id')->get();
        $numbers = $this->generator->planFor($students);

        return new MatricPreview($students->map(fn ($s) => [$s, $numbers[$s->id]])->all());
    }

    private function selected(MatricSelection $selection): \Illuminate\Database\Eloquent\Builder
    {
        return $this->waiting()
            ->when($selection->studentIds, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($selection->programmeId, fn ($q, $p) => $q->where('programme_id', $p));
    }

    /** @return Collection<int, object> recent batches with how many numbers each still holds */
    public function batches(int $limit = 10): Collection
    {
        return MatricEvent::whereIn('action', ['generated', 'external'])->whereNotNull('batch')
            ->selectRaw('batch, min(created_at) as at, count(*) as total, max(action) as kind')->groupBy('batch')->orderByDesc('at')->limit($limit)->get();
    }
}
