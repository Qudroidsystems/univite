<?php

namespace App\Listeners;

use App\Events\StudentProgressChanged;
use App\Services\Accommodation\AccommodationService;

/** A paid accommodation invoice confirms the bed it was raised for. */
class ConfirmPaidAccommodation
{
    public function __construct(private AccommodationService $accommodation)
    {
    }

    public function handle(StudentProgressChanged $event): void
    {
        $this->accommodation->confirmPaid($event->student);
    }
}
