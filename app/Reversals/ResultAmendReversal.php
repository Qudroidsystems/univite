<?php

namespace App\Reversals;

use App\Services\OperationAudit;
use App\Models\CourseResult;
use App\Models\OperationBatch;
use App\Models\User;
use App\Services\DocumentMailer;
use App\Services\ResultService;

/** Put a corrected score back to what it was, as a new correction. Refused if the score has changed again since. */
class ResultAmendReversal extends BaseReversal
{
    public function __construct(OperationAudit $audit, private ResultService $results, private DocumentMailer $mailer)
    {
        parent::__construct($audit);
    }

    public function title(OperationBatch $op): string
    {
        return 'Reverse this score correction';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $user->can('Amend published results');
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('restore', ['restore' => ['Put back earlier score', 'Record a new correction that puts the score back. Both corrections stay on the history.']]);
        $event = $op->events()->first();
        $result = $event ? CourseResult::with('student', 'course')->find($event->subject_id) : null;
        if (! $result) {
            $plan->blockers[] = 'The result is no longer on record.';

            return $plan;
        }
        $label = ($result->student?->full_name ?? 'Student') . ' · ' . $result->course?->code;
        $now = ['ca' => $result->ca, 'exam' => $result->exam];
        $after = ['ca' => $event->after['ca'] ?? null, 'exam' => $event->after['exam'] ?? null];
        if ((float) $now['ca'] !== (float) $after['ca'] || (float) $now['exam'] !== (float) $after['exam']) {
            $plan->block($label, "{$result->ca} + {$result->exam} ({$result->grade})", 'The score has been changed again since. Reverse the later correction first.', $result->id);
            $plan->blockers[] = 'The score has been changed again since this correction.';
        } elseif ($result->status !== 'published') {
            $plan->blockers[] = 'The result is no longer published.';
        } elseif ($result->student?->graduated_at) {
            $plan->blockers[] = 'The student has graduated. Reverse the graduation first.';
        } else {
            $plan->change($label, "{$result->ca} + {$result->exam} ({$result->grade})", ($event->before['ca'] ?? '') . ' + ' . ($event->before['exam'] ?? '') . ' (' . ($event->before['grade'] ?? '') . ')', $result->id);
        }
        $plan->outcome = 'The score goes back to its earlier value as a new, recorded correction. The student is emailed.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        $event = $op->events()->first();
        $result = CourseResult::findOrFail($event->subject_id);
        $before = ['ca' => $result->ca, 'exam' => $result->exam, 'grade' => $result->grade];
        $new = $this->begin($op, 'result_amend_reverse', $by, $reason);
        $this->results->amend($result, $event->before['ca'], $event->before['exam'], 'Correction ' . $op->operation_id . ' reversed: ' . $reason, $by, record: false, items: $event->before['items'] ?? null);
        $result->refresh();
        $this->audit->record($new, 'score_restored', $result, $before, ['ca' => $result->ca, 'exam' => $result->exam, 'grade' => $result->grade], $reason, $event->id);
        $this->audit->finish($new, 1);
        $this->audit->markReversed($op);
        $this->mailer->resultNotice($result, 'restored', $before, $reason, $by);

        return $new;
    }
}
