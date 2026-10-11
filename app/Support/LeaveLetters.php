<?php

namespace App\Support;

use App\Models\LeaveRequest;

/** The official letters of a leave application, built once and used for both the email and the printed copy. */
class LeaveLetters
{
    public const KINDS = [
        'acknowledgement' => 'Acknowledgement of leave application',
        'approval' => 'Leave approval letter',
        'rejection' => 'Leave application not approved',
        'returned' => 'Leave application returned for correction',
        'cancelled' => 'Leave cancellation',
        'resumption' => 'Resumption of duty',
    ];

    public static function doc(LeaveRequest $r, string $kind): array
    {
        $r->loadMissing('user.staffProfile.department', 'type', 'actingOfficer', 'decider', 'steps.actor');
        $p = $r->user->staffProfile;
        $first = explode(' ', trim($r->user->name))[0] ?? '';
        $details = array_filter([
            'Reference' => $r->reference,
            'Name' => $r->user->name,
            'Staff number' => $p?->staff_number,
            'Department / unit' => $p?->department?->name ?? $p?->unit,
            'Leave type' => $r->type->name,
            'From' => $r->start_date->format('l j F Y'),
            'To' => $r->end_date->format('l j F Y'),
            'Days' => $r->days . ' ' . ($r->type->working_days ? 'working day(s)' : 'day(s)'),
            'Expected resumption' => $r->expected_resumption->format('l j F Y'),
            'Acting officer' => $r->actingOfficer?->name,
        ]);
        $intro = match ($kind) {
            'acknowledgement' => ["Your application for {$r->type->name} has been received and sent for approval. You will be told of each decision."],
            'approval' => ["Your application for {$r->type->name} has been approved. You are expected to resume duty on " . $r->expected_resumption->format('l j F Y') . '.', 'Please hand over your duties before you proceed on leave.'],
            'rejection' => ["Your application for {$r->type->name} has not been approved."],
            'returned' => ["Your application for {$r->type->name} has been returned to you for correction. Please update it in the portal and submit it again."],
            'cancelled' => ["Your {$r->type->name} application has been cancelled."],
            'resumption' => ['This confirms your resumption of duty on ' . $r->resumed_on?->format('l j F Y') . '.'],
            default => [],
        };
        if (in_array($kind, ['approval', 'rejection', 'returned', 'cancelled'], true)) {
            $details['Decided by'] = trim(($r->decider?->name ?? '') . ($r->decided_at ? ', ' . $r->decided_at->format('j M Y') : ''), ', ');
            $r->decision_note && $details[$kind === 'approval' ? 'Note' : 'Reason'] = $r->decision_note;
        }
        if ($kind === 'resumption') {
            $details['Resumed on'] = $r->resumed_on?->format('l j F Y');
            $details['Days of leave used'] = (string) $r->days_charged;
            $r->late_days && $details['Late resumption'] = $r->late_days . ' working day(s) after the expected date';
        }
        $approvals = $r->steps->whereIn('status', ['approved', 'rejected', 'returned'])->map(fn ($s) => [$s->label, $s->actor?->name ?? '', ucfirst($s->status), $s->acted_at?->format('j M Y') ?? ''])->values()->all();

        return [
            'title' => self::KINDS[$kind] ?? 'Leave letter',
            'banner' => match ($kind) {
                'approval' => ['Approved', 'success'],
                'rejection' => ['Not approved', 'danger'],
                'returned', 'cancelled' => [$kind === 'returned' ? 'Returned for correction' : 'Cancelled', 'warning'],
                default => null,
            },
            'greeting' => $first ? "Dear {$first}," : null,
            'intro' => $intro,
            'details' => $details,
            'table' => $approvals && $kind !== 'acknowledgement' ? ['head' => ['Approval step', 'By', 'Decision', 'Date'], 'rows' => $approvals] : null,
            'signatory' => in_array($kind, ['approval', 'rejection', 'resumption'], true) ? 'registrar' : null,
        ];
    }
}
