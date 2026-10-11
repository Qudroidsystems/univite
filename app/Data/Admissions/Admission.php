<?php

namespace App\Data\Admissions;

use App\Models\Student;

/**
 * An applicant turned into a student. The temporary password is shown to the admin once; it is null for applicants
 * who applied online, because they keep the password they chose.
 */
final readonly class Admission
{
    public function __construct(public Student $student, public ?string $temporaryPassword = null)
    {
    }
}
