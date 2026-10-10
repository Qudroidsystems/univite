<?php

namespace App\Reversals;

use App\Models\GraduationList;
use App\Models\OperationBatch;
use App\Models\Student;
use App\Models\User;
use App\Services\DocumentMailer;
use Illuminate\Support\Facades\DB;

/** Reverse an approved graduation list: the students go back to what they were before and the list back to draft. */
class GraduationReversal extends BaseReversal
{
    public function title(OperationBatch $op): string
    {
        return 'Reverse this graduation approval';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $user->can('Reverse graduation approvals');
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('reverse', ['reverse' => ['Reverse approval', 'Return the students to their status before graduation and the list to draft. The approval stays on the history.']]);
        $list = $this->subject($op, GraduationList::class);
        if (! $list || $list->status !== 'approved') {
            $plan->blockers[] = 'The graduation list is no longer approved.';

            return $plan;
        }
        foreach ($op->events()->where('action', 'student_graduated')->get() as $e) {
            $student = Student::find($e->subject_id);
            if (! $student) {
                $plan->skip($e->label ?? 'Student', 'Graduated', 'The student record no longer exists.', $e->id);
            } elseif (! $student->graduated_at || $student->degree_class !== ($e->after['degree_class'] ?? null)) {
                $plan->skip($e->label ?? $student->full_name, $student->status, 'Changed since the approval; left as it is.', $e->id);
            } else {
                $plan->change($e->label ?? $student->full_name, 'Graduated · ' . $student->degree_class, 'Back to ' . ($e->before['status'] ?? 'previous status'), $e->id);
            }
        }
        $plan->outcome = 'The students return to their status before the approval, the list returns to draft for review, and each student is emailed.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        $new = $this->begin($op, 'graduation_reverse', $by, $reason);
        $list = $this->subject($op, GraduationList::class);
        $ids = [];
        DB::transaction(function () use ($plan, $new, $list, $reason, &$ids) {
            foreach ($plan->changes() as $item) {
                $e = \App\Models\OperationEvent::find($item['key']);
                $student = Student::lockForUpdate()->find($e->subject_id);
                $now = ['status' => $student->status, 'graduated_at' => $student->graduated_at?->toDateTimeString(), 'degree_class' => $student->degree_class, 'final_cgpa' => $student->final_cgpa];
                $student->update(['status' => $e->before['status'] ?? $student->status, 'graduated_at' => $e->before['graduated_at'] ?? null, 'degree_class' => $e->before['degree_class'] ?? null, 'final_cgpa' => $e->before['final_cgpa'] ?? null]);
                $this->audit->record($new, 'graduation_reversed', $student, $now, $e->before, $reason, $e->id);
                $ids[] = $student->id;
            }
            $this->audit->record($new, 'list_reopened', $list, ['status' => 'approved'], ['status' => 'draft'], $reason);
            $list->update(['status' => 'draft']);
        });
        $c = $plan->counts();
        $this->audit->finish($new, count($ids), 0, $c['skip'] + $c['blocked']);
        $this->audit->markReversed($op);
        $mailer = app(DocumentMailer::class);
        Student::whereIn('id', $ids)->get()->each(fn ($s) => $mailer->graduation($s, false, $reason, $by));

        return $new;
    }
}
