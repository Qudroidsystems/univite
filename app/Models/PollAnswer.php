<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One answer on a ballot. Secret ballots carry no user id, and keys are random so ballots cannot be put in voting order. */
class PollAnswer extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['poll_id', 'poll_question_id', 'poll_option_id', 'text', 'ballot', 'user_id'];

    protected static function booted(): void
    {
        static::creating(fn (self $a) => $a->id ??= (string) Str::uuid());
    }
}
