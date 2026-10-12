<?php

namespace App\Data\Access;

use App\Models\StaffProfile;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

/**
 * What part of the university a permission reaches for one person: everything (the permission is held outright),
 * some departments and units (the permission comes from a role held for a faculty, department or unit), or nothing.
 * A faculty is stored as the departments in it.
 */
final readonly class AccessScope
{
    /**
     * @param  int[]  $departmentIds
     * @param  int[]  $unitIds
     */
    public function __construct(public bool $all = false, public array $departmentIds = [], public array $unitIds = [])
    {
    }

    public static function everything(): self
    {
        return new self(true);
    }

    public static function nothing(): self
    {
        return new self(false);
    }

    public function isEmpty(): bool
    {
        return ! $this->all && $this->departmentIds === [] && $this->unitIds === [];
    }

    public function coversDepartment(?int $id): bool
    {
        return $this->all || ($id !== null && in_array($id, $this->departmentIds, true));
    }

    public function coversUnit(?int $id): bool
    {
        return $this->all || ($id !== null && in_array($id, $this->unitIds, true));
    }

    public function coversStudent(Student $student): bool
    {
        return $this->all || $this->coversDepartment($student->programme?->department_id);
    }

    public function coversStaff(?StaffProfile $profile): bool
    {
        return $this->all || ($profile && ($this->coversDepartment($profile->department_id) || $this->coversUnit($profile->unit_id)));
    }

    /** Limit a Student query (or a query on a model with a "student" relation, given as $through). */
    public function students(Builder $q, ?string $through = null): Builder
    {
        if ($this->all) {
            return $q;
        }
        $path = $through ? "{$through}.programme" : 'programme';

        return $this->departmentIds ? $q->whereHas($path, fn ($p) => $p->whereIn('department_id', $this->departmentIds)) : $q->whereRaw('1 = 0');
    }

    /** Limit a StaffProfile query. */
    public function staffProfiles(Builder $q): Builder
    {
        if ($this->all) {
            return $q;
        }
        if ($this->isEmpty()) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(fn ($w) => $w->whereIn('department_id', $this->departmentIds ?: [0])->orWhereIn('unit_id', $this->unitIds ?: [0]));
    }

    /** Limit a query on a model with a "user" relation (leave requests ...) to staff in scope. */
    public function staffUsers(Builder $q, string $through = 'user'): Builder
    {
        return $this->all ? $q : $q->whereHas("{$through}.staffProfile", fn ($p) => $this->staffProfiles($p));
    }
}
