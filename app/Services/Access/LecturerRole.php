<?php

namespace App\Services\Access;

use App\Models\Setting;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Someone a course is defined for needs "Enter results for assigned courses" to enter its scores. When they do not
 * have it yet they are given the lecturer role, whose name is the setting courses.lecturer_role (default "Lecturer";
 * blank turns this off).
 */
class LecturerRole
{
    public const PERMISSION = 'Enter results for assigned courses';

    public function name(): ?string
    {
        $name = Setting::get('courses.lecturer_role', 'Lecturer');

        return $name === '' ? null : $name;
    }

    public function ensure(User $user): void
    {
        if ($user->student || $user->can(self::PERMISSION) || ! ($name = $this->name())) {
            return;
        }
        if ($role = Role::where('name', $name)->where('guard_name', 'web')->first()) {
            $user->assignRole($role);
        }
    }
}
