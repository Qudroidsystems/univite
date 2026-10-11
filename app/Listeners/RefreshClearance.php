<?php

namespace App\Listeners;

use App\Events\StudentProgressChanged;
use App\Services\ClearanceService;

/** Re-check clearance whenever a step it depends on changes. Runs straight away, inside the same request. */
class RefreshClearance
{
    public function __construct(private ClearanceService $clearance)
    {
    }

    public function handle(StudentProgressChanged $event): void
    {
        $this->clearance->refresh($event->student->fresh());
    }
}
