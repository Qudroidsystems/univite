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
        'View leave applications' => 'Leave of staff in the department, faculty or unit',
        'View staff records' => 'Records of staff in the department, faculty or unit (pay details stay hidden)',
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
