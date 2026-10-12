<?php

namespace App\Services\Campus;

use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\User;

/** Finds a student or member of staff from what a desk officer types: matric number, staff number, email or username. */
class PersonFinder
{
    public function find(?string $typed): ?User
    {
        $t = trim((string) $typed);
        if ($t === '') {
            return null;
        }
        $student = Student::where('matric_number', $t)->orWhere('provisional_matric', $t)->first();
        if ($student?->user) {
            return $student->user;
        }
        $staff = StaffProfile::where('staff_number', $t)->first();
        if ($staff?->user) {
            return $staff->user;
        }

        return User::where('email', $t)->orWhere('username', $t)->first();
    }

    /** "Ada Obi (AFU/2023/001)" */
    public function label(User $user): string
    {
        $id = $user->student?->matric_number ?? $user->staffProfile?->staff_number;

        return ($user->student?->full_name ?: $user->name) . ($id ? " ({$id})" : '');
    }
}
