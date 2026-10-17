<?php

namespace App\Reversals;

use App\Models\OperationBatch;
use App\Models\RegistrationForm;
use App\Models\StandingWaiver;
use App\Models\User;
use App\Services\Access\AcademicRuleAccess;
use App\Services\OperationAudit;

/** Withdraw an allowance that let a student on probation or advised to withdraw register for a session. */
class WaiverReversal extends BaseReversal
{
    public function __construct(OperationAudit $audit, private AcademicRuleAccess $rules)
    {
        parent::__construct($audit);
    }

    public function title(OperationBatch $op): string
    {
        return 'Withdraw this allowance';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        $waiver = $this->subject($op, StandingWaiver::class);

        return $waiver && $this->rules->canEdit($user, 'programme', $waiver->student?->programme_id);
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('withdraw', ['withdraw' => ['Withdraw', 'The student can no longer start a registration this session. A form already submitted or approved is kept.']]);
        $waiver = $this->subject($op, StandingWaiver::class)?->load('student');
        if (! $waiver) {
            $plan->blockers[] = 'The allowance has already been removed.';

            return $plan;
        }
        $plan->change($waiver->student?->full_name ?? 'Student', 'Allowed to register', 'Not allowed', $waiver->id);
        $forms = RegistrationForm::where('student_id', $waiver->student_id)->where('academic_session_id', $waiver->academic_session_id)->whereIn('status', ['submitted', 'approved'])->count();
        if ($forms) {
            $plan->warnings[] = "The student has already submitted {$forms} course form(s) this session. They are kept; reopen or return them separately if needed.";
        }
        $plan->outcome = 'The allowance is withdrawn. Its grant and withdrawal both stay on the history.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        $waiver = StandingWaiver::findOrFail($plan->changes()[0]['key']);
        $new = $this->begin($op, 'standing_waiver_undo', $by, $reason);
        $this->audit->record($new, 'allowance_withdrawn', $waiver, ['note' => $waiver->note, 'granted_by' => $waiver->granted_by], null, $reason);
        $waiver->delete();
        $this->audit->finish($new, 1);
        $this->audit->markReversed($op);

        return $new;
    }
}
