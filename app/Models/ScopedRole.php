<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/** A role held only for one faculty, department or unit. Heads get theirs automatically (source "head"). */
class ScopedRole extends Model
{
    public const SCOPES = ['faculty' => 'Faculty', 'department' => 'Department', 'unit' => 'Unit'];

    protected $fillable = ['user_id', 'role_id', 'scope_type', 'scope_id', 'source', 'created_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function scopeName(): string
    {
        $model = ['faculty' => Faculty::class, 'department' => Department::class, 'unit' => Unit::class][$this->scope_type] ?? null;

        return ($model ? $model::find($this->scope_id)?->name : null) ?? '(removed)';
    }
}
