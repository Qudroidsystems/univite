<?php

namespace App\Support;

use App\Models\OperationBatch;
use App\Reversals as R;

/** Names of the operations in the log, and which of them can be reversed or corrected (and how). */
class Reversals
{
    /** type => [label, reversal handler or null] */
    public const TYPES = [
        'matric_generate'       => ['Matric numbers generated', R\MatricBatchReversal::class],
        'matric_import'         => ['Matric numbers imported', R\MatricBatchReversal::class],
        'matric_special'        => ['Matric number set by hand', null],
        'matric_propose'        => ['Matric numbers reserved for approval', null],
        'matric_reject'         => ['Matric batch rejected', null],
        'matric_sequence'       => ['Matric running number set', null],
        'matric_cancel'         => ['Matric number cancelled', null],
        'matric_restore'        => ['Matric number restored', null],
        'matric_undo'           => ['Matric numbers taken back', null],
        'matric_correct'        => ['Matric numbers corrected', null],
        'session_clone'         => ['Session setup copied', R\SessionCloneReversal::class],
        'session_clone_undo'    => ['Session copy undone', null],
        'snapshot_restore'      => ['Setup snapshot restored', null],
        'session_status'        => ['Session status changed', null],
        'payment_record'        => ['Payment recorded', R\PaymentReversal::class],
        'payment_reverse'       => ['Payment reversed', null],
        'invoice_generate'      => ['Invoices generated', R\InvoiceBatchReversal::class],
        'invoice_cancel_batch'  => ['Generated invoices cancelled', null],
        'result_publish'        => ['Results published', R\ResultPublishReversal::class],
        'result_withdraw'       => ['Published results withdrawn', null],
        'result_amend'          => ['Published score corrected', R\ResultAmendReversal::class],
        'result_amend_reverse'  => ['Score correction reversed', null],
        'complaint_respond'     => ['Result query answered', null],
        'graduation_approve'    => ['Graduation list approved', R\GraduationReversal::class],
        'graduation_reverse'    => ['Graduation approval reversed', null],
        'registration_approve'  => ['Course form approved', R\RegistrationApprovalReversal::class],
        'registration_return'   => ['Course form returned', null],
        'registration_reopen'   => ['Course form reopened', null],
        'rules_change'          => ['Academic rules changed', R\SettingsReversal::class],
        'rules_restore'         => ['Academic rules change reversed', null],
        'institution_update'    => ['Institution profile changed', R\SettingsReversal::class],
        'institution_restore'   => ['Institution profile change reversed', null],
        'standing_waiver'       => ['Student allowed to register despite standing', R\WaiverReversal::class],
        'standing_waiver_undo'  => ['Standing allowance withdrawn', null],
        'venue_change'          => ['Venue details changed', null],
        'venue_closure'         => ['Venue closed', null],
        'venue_reopen'          => ['Venue closure lifted', null],
        'venue_fault'           => ['Venue fault resolved', null],
        'timetable_generate'    => ['Timetable generated', null],
        'timetable_move'        => ['Timetable entry moved by hand', null],
        'timetable_submit'      => ['Timetable sent for approval', null],
        'timetable_approve'     => ['Timetable approved', null],
        'timetable_return'      => ['Timetable returned to the committee', null],
        'timetable_publish'     => ['Timetable published', null],
        'timetable_revise'      => ['Timetable revision started', null],
        'seating_generate'      => ['Examination seating generated', null],
        'seating_approve'       => ['Examination seating approved', null],
        'seating_publish'       => ['Examination seating published', null],
        'seating_move'          => ['Candidate moved to another seat', null],
        'leave_submit'          => ['Leave application submitted', null],
        'leave_decide'          => ['Leave application decided', null],
        'leave_cancel'          => ['Leave cancelled', null],
        'leave_resume'          => ['Resumption recorded', null],
        'leave_adjust'          => ['Leave balance adjusted', null],
        'leave_carryover'       => ['Leave balances carried over', null],
        'leave_policy'          => ['Leave settings or approval route changed', null],
        'messaging_settings'    => ['Messaging settings changed', null],
        'legacy_import'         => ['Legacy results imported', R\LegacyImportReversal::class],
        'legacy_import_undo'    => ['Legacy results import undone', null],
        'payroll_approve'       => ['Payroll approved', R\PayrollApprovalReversal::class],
        'payroll_reopen'        => ['Payroll reopened', null],
        'payroll_paid'          => ['Payroll marked paid', null],
        'payroll_policy'        => ['Payroll settings changed', null],
        'staff_pay_details'     => ['Staff pay or bank details changed', null],
        'staff_pay_item'        => ['Personal earning or deduction changed', null],
        'scoped_role'           => ['Scoped role given or removed', null],
        'olevel_confirm'        => ['O Level result confirmed', null],
        'olevel_return'         => ['O Level result returned to the student', null],
        'olevel_override'       => ['Student allowed to register before O Level is confirmed', null],
        'olevel_requirement'    => ['O Level requirement changed', null],
        'fee_access_rules'      => ['Fee and access rules changed', null],
        'fee_access_override'   => ['Student allowed or barred despite fees', null],
        'loan_decide'           => ['School loan approved or declined', null],
        'loan_disburse'         => ['School loan paid against fees', null],
        'loan_repayment'        => ['Loan repayment recorded', null],
        'loan_writeoff'         => ['School loan closed or written off', null],
        'senate_decision'       => ['Senate decision recorded', null],
    ];

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type][0] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /** The handler for this operation, or null when it cannot be reversed from the history. */
    public static function for(OperationBatch $op): ?R\Reversal
    {
        $class = self::TYPES[$op->type][1] ?? null;
        if (! $class || in_array($op->status, ['reversed', 'corrected', 'failed'], true)) {
            return null;
        }

        return app($class);
    }

    /** The operation that recorded a record (e.g. the payment_record operation of a payment). */
    public static function originOf(string $type, string $subjectType, int $subjectId): ?OperationBatch
    {
        return OperationBatch::where('type', $type)
            ->whereHas('events', fn ($e) => $e->where('subject_type', $subjectType)->where('subject_id', $subjectId))
            ->latest('id')->first();
    }
}
