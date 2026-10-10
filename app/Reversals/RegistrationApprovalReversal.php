<?php

namespace App\Reversals;

use App\Models\CourseResult;
use App\Models\OperationBatch;
use App\Models\RegistrationForm;
use App\Models\User;

/** Send an approved course form back for approval again. Not once scores have been entered for the student that semester. */
class RegistrationApprovalReversal extends BaseReversal
{
    public function title(OperationBatch $op): string
    {
        return 'Reverse this approval';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $user->can('Approve course registrations');
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('reverse', ['reverse' => ['Reverse approval', 'The form goes back to "submitted" so it can be checked and approved or returned again.']]);
        $form = $this->subject($op, RegistrationForm::class)?->load('student');
        if (! $form || $form->status !== 'approved') {
            $plan->blockers[] = 'The form is no longer approved.';

            return $plan;
        }
        $scores = CourseResult::where('student_id', $form->student_id)->where('academic_session_id', $form->academic_session_id)
            ->where('semester_type_id', $form->semester_type_id)->whereNotNull('total')->count();
        if ($scores) {
            $plan->block($form->student?->full_name ?? 'Student', 'Approved', "{$scores} score(s) have already been entered for this semester.", $form->id);
            $plan->blockers[] = 'Scores have already been entered against this registration.';

            return $plan;
        }
        $plan->change($form->student?->full_name ?? 'Student', 'Approved', 'Submitted (awaiting approval)', $form->id);
        $plan->outcome = 'The form goes back to the approval queue. The student keeps the courses they chose.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        $form = RegistrationForm::findOrFail($plan->changes()[0]['key']);
        $new = $this->begin($op, 'registration_reopen', $by, $reason);
        $before = ['status' => $form->status, 'decided_by' => $form->decided_by, 'decided_at' => $form->decided_at?->toDateTimeString()];
        $form->update(['status' => 'submitted', 'decided_by' => null, 'decided_at' => null]);
        $this->audit->record($new, 'approval_reversed', $form, $before, ['status' => 'submitted'], $reason);
        $this->audit->finish($new, 1);
        $this->audit->markReversed($op);

        return $new;
    }
}
