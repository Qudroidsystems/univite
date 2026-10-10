<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Advises a programme's students at one level and approves their registration forms. */
class CourseAdviser extends Model
{
    protected $fillable = ['programme_id', 'level', 'user_id'];

    protected $casts = ['level' => 'integer'];

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether this user advises the student's programme at the form's level. */
    public static function advises(User $user, int $programmeId, int $level): bool
    {
        return static::where(['user_id' => $user->id, 'programme_id' => $programmeId, 'level' => $level])->exists();
    }
}
