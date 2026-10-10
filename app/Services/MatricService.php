<?php

namespace App\Services;

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
    public function __construct(private ClearanceService $clearance, private MatricNumberGenerator $generator)
    {
    }

    /** Cleared students still waiting for a number, in the order numbers will be handed out. */
    public function waiting(): \Illuminate\Database\Eloquent\Builder
    {
        return Student::query()->whereNotNull('cleared_at')->whereNull('matric_number')
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
     * @param array<int>|null $studentIds only these students (must be waiting); null = every waiting student
     * @return array{batch: string, issued: int}
     */
    public function generateBatch(?array $studentIds, ?int $programmeId, User $by): array
    {
        $batch = strtoupper(Str::random(8));
        $issued = 0;
        $audit = app(OperationAudit::class);
        $op = $audit->begin('matric_generate', 'matric', $by, null, null, ['batch' => $batch, 'programme_id' => $programmeId]);

        $this->waiting()
            ->when($studentIds, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($programmeId, fn ($q, $p) => $q->where('programme_id', $p))
            ->orderBy('programme_id')->orderBy('academic_session_id')->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->each(function (Student $student) use ($by, $batch, &$issued, $audit, $op) {
                $this->clearance->issueMatric($student, $by, 'generated', $batch);
                $audit->record($op, 'matric_issued', $student, null, ['matric_number' => $student->fresh()->matric_number]);
                $issued++;
            });

        if (! $issued) {
            $audit->finish($op, 0, 0, 0);
            $op->update(['status' => 'failed']);
            throw new RuntimeException('There are no cleared students waiting for a matric number.');
        }
        $audit->finish($op, $issued);

        return ['batch' => $batch, 'issued' => $issued];
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
    public function importExternal(iterable $rows, User $by): array
    {
        $applied = 0;
        $skipped = [];
        $batch = strtoupper(Str::random(8));

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
                $applied++;
            } catch (RuntimeException $e) {
                $skipped[] = "{$line}: " . $e->getMessage();
            }
        }

        return ['applied' => $applied, 'skipped' => $skipped, 'batch' => $batch];
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
        });

        return $last->matric_number;
    }

    /** Take back every number a batch handed out (as long as the student still holds it) so they can be issued again. */
    public function undoBatch(string $batch, User $by): int
    {
        $events = MatricEvent::where('batch', $batch)->whereIn('action', ['generated', 'external'])->get();
        if ($events->isEmpty()) {
            throw new RuntimeException('There is no batch with that code.');
        }

        $undone = 0;
        $audit = app(OperationAudit::class);
        $original = OperationBatch::where('type', 'matric_generate')->where('meta->batch', $batch)->first();
        $op = $audit->begin('matric_undo', 'matric', $by, null, null, ['batch' => $batch], $original);
        DB::transaction(function () use ($events, $batch, $by, &$undone, $audit, $op) {
            foreach ($events as $event) {
                $student = Student::find($event->student_id);
                if (! $student || $student->matric_number !== $event->matric_number) {
                    continue; // cancelled or changed since; leave it alone
                }
                $this->clearance->assignMatric($student, null);
                MatricEvent::create(['student_id' => $student->id, 'action' => 'undone', 'matric_number' => $event->matric_number, 'batch' => $batch, 'performed_by' => $by->id, 'note' => 'Batch undone']);
                $this->generator->rewind($student);
                $audit->record($op, 'matric_taken_back', $student, ['matric_number' => $event->matric_number], ['matric_number' => null]);
                $undone++;
            }
        });

        $audit->finish($op, $undone, 0, $events->count() - $undone);
        if ($undone && $original && $undone === $events->count()) {
            $audit->markReversed($original);
        }
        if (! $undone) {
            throw new RuntimeException('None of that batch\'s numbers could be undone: they have all been changed or cancelled since.');
        }

        return $undone;
    }

    /** @return Collection<int, object> recent batches with how many numbers each still holds */
    public function batches(int $limit = 10): Collection
    {
        return MatricEvent::whereIn('action', ['generated', 'external'])->whereNotNull('batch')
            ->selectRaw('batch, min(created_at) as at, count(*) as total, max(action) as kind')->groupBy('batch')->orderByDesc('at')->limit($limit)->get();
    }
}
