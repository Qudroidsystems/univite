<?php

namespace App\Services\Access;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\ScopedRole;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Heads get a role for what they head: the head of department gets the department head role for that department,
 * the dean gets the dean role for the faculty, and the head of a unit gets the unit head role for the unit. When the
 * head changes, the role moves to the new head. Which role each kind of head gets is a setting.
 */
class HeadRoles
{
    /** scope type => [model, head column, setting key, default role name] */
    public const HEADS = [
        'department' => [Department::class, 'hod_id', 'heads.department_role', 'Head of Department'],
        'faculty' => [Faculty::class, 'dean_id', 'heads.faculty_role', 'Dean'],
        'unit' => [Unit::class, 'head_id', 'heads.unit_role', 'Head of Unit'],
    ];

    public function __construct(private ScopedAccess $access)
    {
    }

    public function roleName(string $type): ?string
    {
        [, , $key, $default] = self::HEADS[$type];
        $name = Setting::get($key, $default);

        return $name === '' ? null : $name;
    }

    public static function typeOf(Model $m): ?string
    {
        foreach (self::HEADS as $type => [$class]) {
            if ($m instanceof $class) {
                return $type;
            }
        }

        return null;
    }

    /** Give the head of this department, faculty or unit its role there, taking it from anyone who no longer heads it. */
    public function sync(Model $m): void
    {
        $type = self::typeOf($m);
        if (! $type) {
            return;
        }
        [, $column] = self::HEADS[$type];
        $headId = $m->{$column};
        $role = ($name = $this->roleName($type)) ? Role::where('name', $name)->where('guard_name', 'web')->first() : null;

        ScopedRole::where(['scope_type' => $type, 'scope_id' => $m->getKey(), 'source' => 'head'])
            ->where(fn ($q) => $q->where('user_id', '!=', $headId ?? 0)->orWhere('role_id', '!=', $role?->id ?? 0))->delete();
        if ($headId && $role) {
            ScopedRole::firstOrCreate(['user_id' => $headId, 'role_id' => $role->id, 'scope_type' => $type, 'scope_id' => $m->getKey()], ['source' => 'head']);
        }
        $this->access->forget();
    }

    public function forgetScope(Model $m): void
    {
        if ($type = self::typeOf($m)) {
            ScopedRole::where(['scope_type' => $type, 'scope_id' => $m->getKey()])->delete();
            $this->access->forget();
        }
    }

    /** Bring every head's role up to date (after a setting changes or on install). @return int heads with a role */
    public function syncAll(): int
    {
        foreach (self::HEADS as [$class]) {
            $class::query()->each(fn ($m) => $this->sync($m));
        }

        return ScopedRole::where('source', 'head')->count();
    }
}
