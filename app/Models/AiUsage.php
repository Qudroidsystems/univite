<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How many AI requests (and tokens) one person used on one day. */
class AiUsage extends Model
{
    protected $fillable = ['user_id', 'day', 'requests', 'input_tokens', 'output_tokens'];

    protected $casts = ['requests' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
}
