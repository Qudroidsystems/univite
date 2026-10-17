<?php

namespace App\Services\Setup;

use App\Data\Setup\RestorePlan;
use App\Models\AcademicSession;

/** One part of the setup that a snapshot can hold and restore. */
interface SetupSection
{
    public function key(): string;

    public function label(): string;

    public function about(): string;

    /** True when the section belongs to one session (bills, windows …); false for university-wide setup. */
    public function perSession(): bool;

    /** The section as it is now, as plain data that can be stored as JSON. */
    public function capture(?AcademicSession $session): array;

    /** How many items the captured data holds, for the snapshot's summary. */
    public function count(array $data): int;

    /** Bring the section back to $data. With $apply false nothing changes and the plan says what would. */
    public function restore(array $data, ?AcademicSession $session, bool $apply): RestorePlan;
}
