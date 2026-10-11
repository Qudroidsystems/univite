<?php

namespace App\Data\Registration;

use App\Models\AcademicSession;
use App\Models\Semester;
use App\Models\SemesterType;

/** The session and semester registration (and result entry) is happening in. */
final readonly class RegistrationContext
{
    public function __construct(public AcademicSession $session, public SemesterType $type, public Semester $semester)
    {
    }

    public function label(): string
    {
        return $this->session->name . ' · ' . $this->type->name;
    }
}
