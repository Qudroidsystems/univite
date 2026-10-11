<?php

namespace App\Data\Leave;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;

/** A leave application as the staff member filled it in. */
final readonly class LeaveApplication
{
    /** @param  UploadedFile[]  $documents */
    public function __construct(
        public int $leaveTypeId,
        public Carbon $start,
        public Carbon $end,
        public ?string $reason = null,
        public ?string $addressDuringLeave = null,
        public ?string $phoneDuringLeave = null,
        public ?int $actingOfficerId = null,
        public array $documents = [],
    ) {
    }

    /** @param  UploadedFile[]  $documents */
    public static function fromArray(array $a, array $documents = []): self
    {
        return new self(
            (int) $a['leave_type_id'],
            Carbon::parse($a['start_date'])->startOfDay(),
            Carbon::parse($a['end_date'])->startOfDay(),
            $a['reason'] ?? null,
            $a['address_during_leave'] ?? null,
            $a['phone_during_leave'] ?? null,
            ! empty($a['acting_officer_id']) ? (int) $a['acting_officer_id'] : null,
            array_values($documents),
        );
    }
}
