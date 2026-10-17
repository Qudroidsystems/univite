<?php

namespace App\Services\Access;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\User;

/**
 * Who may change the academic rules of a scope. "Edit academic rules" edits any scope. "Edit department academic
 * rules" held through a role for a department or faculty (heads get theirs automatically) edits that department and
 * its programmes, or the faculty and everything in it; held outright, it lets a department's administrators edit
 * that department. Nobody is let in for being named head: the role held there decides.
 */
class AcademicRuleAccess
{
    public function __construct(private ScopedAccess $access)
    {
    }

    /** Can this user change the rules of this scope? */
    public function canEdit(User $user, string $type, ?int $id): bool
    {
        if ($user->can('Edit academic rules')) {
            return true;
        }
        if ($type === 'university' || ! $id) {
            return false;
        }

        $department = match ($type) {
            'department' => Department::find($id),
            'programme' => Programme::find($id)?->department,
            default => null,
        };
        $faculty = $type === 'faculty' ? Faculty::find($id) : $department?->faculty;

        // Through a role held for that faculty or department (heads get theirs automatically), or as one of the
        // department's administrators while holding the permission outright.
        $access = $this->access;
        $rule = 'Edit department academic rules';
        if ($type === 'faculty') {
            return $faculty !== null && in_array($faculty->id, $access->faculties($user, $rule), true);
        }

        return $department !== null && ($access->held($user, $rule)->coversDepartment($department->id)
            || ($user->can($rule) && $department->admins()->whereKey($user->id)->exists()));
    }
}
