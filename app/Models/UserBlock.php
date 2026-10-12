<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Someone a person has blocked: no direct messages either way, and their listings and posts are hidden from them. */
class UserBlock extends Model
{
    protected $fillable = ['user_id', 'blocked_user_id'];

    /** Ids of people $userId must not see or be messaged by (blocked either way). */
    public static function hiddenFor(int $userId): array
    {
        return static::where('user_id', $userId)->pluck('blocked_user_id')
            ->merge(static::where('blocked_user_id', $userId)->pluck('user_id'))->unique()->values()->all();
    }
}
