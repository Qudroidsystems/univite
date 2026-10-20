<?php

namespace App\Services\Access;

use App\Data\Access\AccessScope;
use App\Models\Department;
use App\Models\ScopedRole;
use App\Models\User;

/**
 * Works out how far a permission reaches for a person. A permission held through an ordinary role reaches everything.
 * A role held only for a faculty, department or unit (a "scoped" role, such as the one heads get) gives its
 * permissions only there, and only for the permissions listed in SCOPABLE: the pages behind those permissions are
 * the ones that know how to show part of their records.
 */
class ScopedAccess
{
    /** Permissions a scoped role can give, and what they then show. */
    public const SCOPABLE = [
        'View students' => 'Students on programmes in the department or faculty',
        'View student results' => 'Transcripts of those students',
        'View course registrations' => 'Course registration forms of those students',
        'Approve course registrations' => 'Approving or returning those forms',
        'Approve results' => 'Approving score sheets of courses the department or faculty owns, at the steps the role signs',
        'Edit scores while approving' => 'Changing scores on those score sheets while approving them, before they are published',
        'Waive carryover courses' => 'Waiving carry-overs of those students',
        'View leave applications' => 'Leave of staff in the department, faculty or unit',
        'View staff records' => 'Records of staff in the department, faculty or unit (pay details stay hidden)',
        'Compute results' => 'Computing results of the department\'s students and printing its master marks sheet',
        'Define courses for lecturers' => 'Defining (assigning) the department\'s courses to its lecturers',
        'Edit department academic rules' => 'Changing the academic rules of the department and its programmes, or of the faculty and everything in it',
        'Verify O Level results' => 'Confirming or returning the O Level results of the department\'s students',
        'View O Level results' => 'O Level results of the department\'s students',
        'View senate review' => 'The Senate review of the department\'s or faculty\'s programmes and courses',
        'Record senate decisions' => 'Senate decisions on the department\'s or faculty\'s students and courses',
        'View fee access' => 'The fee eligibility list of the department\'s students',
    ];

    /** @var array<string, AccessScope> */
    private array $cache = [];

    public function scope(?User $user, string $permission): AccessScope
    {
        if (! $user) {
            return AccessScope::nothing();
        }
        if ($user->can($permission)) {   // not cached: permissions can change during a request (and between test requests)
            return AccessScope::everything();
        }

        return $this->cache["{$user->id}:{$permission}"] ??= $this->resolve($user, $permission);
    }

    /** True when the person can use the permission anywhere at all. */
    public function any(?User $user, string ...$permissions): bool
    {
        foreach ($permissions as $p) {
            if (! $this->scope($user, $p)->isEmpty()) {
                return true;
            }
        }

        return false;
    }

    /** Faculties where the person holds the permission through a role held for the whole faculty. @return int[] */
    public function faculties(?User $user, string $permission): array
    {
        if (! $user || ! array_key_exists($permission, self::SCOPABLE)) {
            return [];
        }

        return ScopedRole::with('role.permissions')->where('user_id', $user->id)->where('scope_type', 'faculty')->get()
            ->filter(fn ($r) => $r->role && $r->role->permissions->contains('name', $permission))
            ->pluck('scope_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Where the person holds the permission through roles held for a faculty, department or unit only (ignoring roles held outright). */
    public function held(?User $user, string $permission): AccessScope
    {
        return $user ? $this->resolve($user, $permission) : AccessScope::nothing();
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function resolve(User $user, string $permission): AccessScope
    {
        if (! array_key_exists($permission, self::SCOPABLE)) {
            return AccessScope::nothing();
        }

        $rows = ScopedRole::with('role.permissions')->where('user_id', $user->id)->get()
            ->filter(fn ($r) => $r->role && $r->role->permissions->contains('name', $permission));
        $faculties = $rows->where('scope_type', 'faculty')->pluck('scope_id')->all();
        $departments = $rows->where('scope_type', 'department')->pluck('scope_id')
            ->merge($faculties ? Department::whereIn('faculty_id', $faculties)->pluck('id') : [])
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
        $units = $rows->where('scope_type', 'unit')->pluck('scope_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        return new AccessScope(false, $departments, $units);
    }
}
