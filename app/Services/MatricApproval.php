<?php

namespace App\Services;

use App\Models\MatricBatch;
use App\Models\MatricEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Approval of reserved matric numbers. When approval is switched on, generated numbers wait in a batch; the person in
 * charge (Senate, a committee, the Registrar ...) checks the batch and approves it, which publishes the numbers to the
 * students, or rejects it, which releases the numbers so they can be issued again.
 */
class MatricApproval
{
    public function __construct(private ClearanceService $clearance, private MatricNumberGenerator $generator, private OperationAudit $audit, private DocumentMailer $mailer)
    {
    }

    public function newBatch(?User $by, string $source = 'generated'): MatricBatch
    {
        return MatricBatch::open($by, $source);
    }

    /** Numbers reserved automatically (on clearance or admission) collect in one open batch until it is decided. */
    public function autoBatch(?User $by = null): MatricBatch
    {
        return MatricBatch::openAuto($by);
    }

    /**
     * Each student in a pending batch with any reason their number cannot be published.
     *
     * @return Collection<int, array{student: Student, number: string, problem: ?string}>
     */
    public function check(MatricBatch $batch): Collection
    {
        $students = $batch->students()->with('programme')->orderBy('provisional_matric')->get();
        $counts = $students->countBy('provisional_matric');

        return $students->map(function (Student $s) use ($counts) {
            $n = $s->provisional_matric;
            $problem = match (true) {
                (bool) $s->matric_number => "Already has the matric number {$s->matric_number}.",
                ($counts[$n] ?? 0) > 1 => 'The same number is reserved twice in this batch.',
                Student::where('matric_number', $n)->exists() => 'Another student already holds this number.',
                MatricEvent::whereIn('action', MatricNumberGenerator::RETIRED)->where('matric_number', $n)->exists() => 'This number was cancelled or superseded before.',
                ! in_array($s->status, \App\Models\StudentStatus::codesWhere('is_enrolled'), true) => 'The student is no longer enrolled (' . $s->status . ').',
                default => null,
            };

            return ['student' => $s, 'number' => $n, 'problem' => $problem];
        });
    }

    /** Approve and publish: each student gets their number (and signs in with it) and is told. Problem rows are released. */
    public function approve(MatricBatch $batch, User $by, ?string $note = null): array
    {
        $this->guard($batch, $by);
        $rows = $this->check($batch);
        if ($rows->isEmpty()) {
            throw new RuntimeException('This batch has no numbers waiting.');
        }

        $op = $this->audit->begin('matric_generate', 'matric', $by, null, $note, ['batch' => $batch->code, 'approved_batch' => true]);
        $published = [];
        $released = [];
        DB::transaction(function () use ($rows, $batch, $by, $note, $op, &$published, &$released) {
            foreach ($rows as $row) {
                $s = Student::lockForUpdate()->find($row['student']->id);
                if ($row['problem']) {
                    $this->release($s, $by, 'Not published: ' . $row['problem']);
                    $released[] = $s->full_name . ': ' . $row['problem'];
                    continue;
                }
                $s->update(['provisional_matric' => null, 'provisional_batch' => null]);
                $this->clearance->assignMatric($s, $row['number']);
                MatricEvent::create(['student_id' => $s->id, 'action' => 'generated', 'matric_number' => $row['number'], 'batch' => $batch->code, 'performed_by' => $by->id, 'note' => 'Approved and published' . ($note ? ": {$note}" : '')]);
                $this->audit->record($op, 'matric_published', $s->fresh(), ['matric_number' => null, 'provisional' => $row['number']], ['matric_number' => $row['number']]);
                $published[] = $s->id;
            }
            $batch->update(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);
        });
        $this->audit->finish($op, count($published), 0, count($released));

        $mailer = $this->mailer;
        foreach (Student::whereIn('id', $published)->get() as $s) {
            $this->clearance->notify($s, 'Your matric number', "Your matric number is {$s->matric_number}. You can now sign in with it.", 'success');
            $mailer->matricNumber($s, 'issued', null, null, $by);
        }

        return ['published' => count($published), 'released' => $released, 'operation' => $op->operation_id];
    }

    /** Reject: every reserved number is released and those students wait for a number again. */
    public function reject(MatricBatch $batch, User $by, string $reason): int
    {
        $this->guard($batch, $by, false);
        if (blank($reason)) {
            throw new RuntimeException('Give the reason for rejecting the batch.');
        }
        $op = $this->audit->begin('matric_reject', 'matric', $by, null, $reason, ['batch' => $batch->code]);
        $n = 0;
        DB::transaction(function () use ($batch, $by, $reason, $op, &$n) {
            foreach ($batch->students()->get() as $s) {
                $this->audit->record($op, 'matric_released', $s, ['provisional' => $s->provisional_matric], ['provisional' => null], $reason);
                $this->release($s, $by, $reason);
                $n++;
            }
            $batch->update(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $reason]);
        });
        $this->audit->finish($op, $n);

        return $n;
    }

    private function release(Student $s, User $by, string $note): void
    {
        MatricEvent::create(['student_id' => $s->id, 'action' => 'released', 'matric_number' => $s->provisional_matric, 'batch' => $s->provisional_batch, 'performed_by' => $by->id, 'note' => $note]);
        $s->update(['provisional_matric' => null, 'provisional_batch' => null]);
        $this->generator->rewind($s);
    }

    private function guard(MatricBatch $batch, User $by, bool $approving = true): void
    {
        if ($batch->status !== 'pending') {
            throw new RuntimeException('This batch has already been ' . $batch->status . '.');
        }
        if ($approving && MatricNumberGenerator::separateApprover() && $batch->created_by === $by->id) {
            throw new RuntimeException('You generated this batch, so someone else must approve it.');
        }
    }
}
