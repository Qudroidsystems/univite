<?php

namespace App\Reversals;

use App\Models\CourseResult;
use App\Models\DocumentEmail;
use App\Models\MatricEvent;
use App\Models\OperationBatch;
use App\Models\Payment;
use App\Models\RegistrationForm;
use App\Models\Student;
use App\Models\User;
use App\Services\ClearanceService;
use App\Services\DocumentMailer;
use App\Services\MatricNumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A batch of generated or imported matric numbers.
 *
 * Take back: numbers nobody has used yet are removed and the students wait for a number again. A number that already
 * appears on a course form, result, receipt or emailed document is not taken back.
 * Correct: every number the batch handed out (used or not) is replaced by a new one in the current format. The old
 * number is kept on record as superseded and is never issued again, because it may be printed on documents.
 */
class MatricBatchReversal extends BaseReversal
{
    public function title(OperationBatch $op): string
    {
        return 'Take back or correct these matric numbers';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $user->can('Undo matric number batches');
    }

    /** @return Collection<int, MatricEvent> the numbers this batch handed out */
    private function issued(OperationBatch $op): Collection
    {
        return MatricEvent::where('batch', $op->meta['batch'] ?? '-')->whereIn('action', ['generated', 'external'])->orderBy('id')->get();
    }

    /** Where a number has been used since it was issued, e.g. ["2 course forms", "1 receipt"]. */
    public static function uses(Student $student, Carbon $since): array
    {
        $forms = RegistrationForm::where('student_id', $student->id)->where('status', '!=', 'draft')->where('updated_at', '>', $since)->count();
        $results = CourseResult::where('student_id', $student->id)->where('created_at', '>', $since)->count();
        $receipts = Payment::whereHas('invoice', fn ($i) => $i->where('payable_type', Student::class)->where('payable_id', $student->id))
            ->whereIn('status', ['successful', 'reversed'])->where('paid_at', '>', $since)->count();
        $emails = $student->user?->email ? DocumentEmail::where('recipient', $student->user->email)->where('status', 'sent')
            ->where('type', '!=', 'matric_number')->where('created_at', '>', $since)->count() : 0;

        return array_values(array_filter([
            $forms ? "{$forms} course form" . ($forms > 1 ? 's' : '') : null,
            $results ? "{$results} result" . ($results > 1 ? 's' : '') : null,
            $receipts ? "{$receipts} receipt" . ($receipts > 1 ? 's' : '') : null,
            $emails ? "{$emails} emailed document" . ($emails > 1 ? 's' : '') : null,
        ]));
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $mode = in_array($mode, ['take_back', 'correct'], true) ? $mode : 'take_back';
        $plan = new ReversalPlan($mode, [
            'take_back' => ['Take back', 'Remove the numbers nobody has used yet. Those students wait for a number again and the freed numbers can be issued again.'],
            'correct'   => ['Correct', 'Give every student in the batch a new number in the current format. The old numbers stay on record as superseded and are never issued again.'],
        ]);

        $events = $this->issued($op);
        if ($events->isEmpty()) {
            $plan->blockers[] = 'This batch has no matric numbers on record.';

            return $plan;
        }

        $held = [];
        foreach ($events as $event) {
            $student = Student::with('programme.department.faculty', 'entrySession', 'user')->find($event->student_id);
            $label = $student?->full_name ?? 'Deleted student #' . $event->student_id;
            if (! $student) {
                $plan->skip($label, $event->matric_number, 'The student record no longer exists.', $event->id);
                continue;
            }
            if ($student->matric_number !== $event->matric_number) {
                $plan->skip($label, $event->matric_number, 'Already changed since: the student now has ' . ($student->matric_number ?: 'no number') . '.', $event->id);
                continue;
            }
            $uses = self::uses($student, $event->created_at);
            if ($mode === 'take_back' && $uses) {
                $plan->block($label, $event->matric_number, 'In use on ' . implode(', ', $uses) . '. Use Correct instead so the change is on record.', $event->id);
                continue;
            }
            $held[] = [$student, $event, $uses];
        }

        $proposed = $mode === 'correct' ? app(MatricNumberGenerator::class)->planFor(array_map(fn ($h) => $h[0], $held)) : [];
        $sameShape = 0;
        foreach ($held as [$student, $event, $uses]) {
            $to = $mode === 'correct' ? ($proposed[$student->id] ?? null) : null;
            if ($to && preg_replace('/\d/', '9', $to) === preg_replace('/\d/', '9', $event->matric_number)) {
                $sameShape++;
            }
            $plan->change($student->full_name, $event->matric_number, $mode === 'correct' ? $to : 'No number (waiting again)', $event->id, $uses ? 'Used on ' . implode(', ', $uses) . '; the old number stays on record.' : null);
        }

        if ($mode === 'correct') {
            $plan->warnings[] = 'New numbers follow the current format: ' . MatricNumberGenerator::pattern() . '. If the format itself was wrong, change it under Matric Number Format before confirming.';
            if ($sameShape && $sameShape === count($held)) {
                $plan->warnings[] = 'The new numbers have the same shape as the old ones. Check that the format has been changed.';
            }
            $plan->outcome = count($held) . ' student(s) get a new number. Each old number is marked superseded, is never issued again and stays on the student\'s history. The students are emailed their new number.';
        } else {
            $plan->outcome = count($held) . ' number(s) will be taken back and those students will wait for a number again. The students are emailed that the number was withdrawn.';
            if ($plan->counts()['blocked']) {
                $plan->warnings[] = $plan->counts()['blocked'] . ' number(s) are already in use and cannot simply be taken back. Choose Correct to replace them with a recorded correction.';
            }
        }

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        $correct = $plan->mode === 'correct';
        $new = $this->begin($op, $correct ? 'matric_correct' : 'matric_undo', $by, $reason, ['batch' => $op->meta['batch'] ?? null, 'mode' => $plan->mode]);
        $clearance = app(ClearanceService::class);
        $generator = app(MatricNumberGenerator::class);
        $code = $correct ? 'C' . strtoupper(\Illuminate\Support\Str::random(7)) : null;
        $done = [];

        DB::transaction(function () use ($plan, $op, $by, $reason, $correct, $clearance, $generator, $new, $code, &$done) {
            foreach ($plan->changes() as $item) {
                $event = MatricEvent::find($item['key']);
                $student = Student::lockForUpdate()->find($event->student_id);
                $old = $event->matric_number;
                if ($correct) {
                    $student->loadMissing('programme.department.faculty', 'entrySession');
                    MatricEvent::create(['student_id' => $student->id, 'action' => 'superseded', 'matric_number' => $old, 'batch' => $event->batch, 'performed_by' => $by->id, 'note' => $reason]);
                    $number = $generator->next($student->programme, $student->entrySession);
                    $clearance->assignMatric($student, $number);
                    MatricEvent::create(['student_id' => $student->id, 'action' => 'corrected', 'matric_number' => $number, 'batch' => $code, 'performed_by' => $by->id, 'note' => "Replaces {$old}. {$reason}"]);
                    $this->audit->record($new, 'matric_corrected', $student->fresh(), ['matric_number' => $old], ['matric_number' => $number], $reason);
                } else {
                    $clearance->assignMatric($student, null);
                    MatricEvent::create(['student_id' => $student->id, 'action' => 'undone', 'matric_number' => $old, 'batch' => $event->batch, 'performed_by' => $by->id, 'note' => $reason]);
                    $generator->rewind($student);
                    $this->audit->record($new, 'matric_taken_back', $student, ['matric_number' => $old], ['matric_number' => null], $reason);
                }
                $done[] = [$student->id, $old];
            }
        });

        $c = $plan->counts();
        $this->audit->finish($new, count($done), 0, $c['skip'] + $c['blocked']);
        if (! $c['blocked'] && ! Student::whereIn('matric_number', $this->issued($op)->pluck('matric_number'))->exists()) {
            $op->update(['status' => $correct ? 'corrected' : 'reversed']);
        }

        $mailer = app(DocumentMailer::class);
        foreach ($done as [$id, $old]) {
            $mailer->matricNumber(Student::find($id), $correct ? 'corrected' : 'taken_back', $old, $reason, $by);
        }

        return $new;
    }
}
