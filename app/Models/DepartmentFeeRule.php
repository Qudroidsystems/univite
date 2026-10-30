<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A department's own fee rule: its students may register courses before paying, and are shown what they owe. */
class DepartmentFeeRule extends Model
{
    protected $fillable = ['department_id', 'register_without_paying', 'show_owing', 'note', 'is_active'];

    protected $casts = ['register_without_paying' => 'boolean', 'show_owing' => 'boolean', 'is_active' => 'boolean'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public static function forStudent(Student $student): ?self
    {
        $departmentId = $student->programme?->department_id;

        return $departmentId ? static::where('department_id', $departmentId)->where('is_active', true)->first() : null;
    }
}
