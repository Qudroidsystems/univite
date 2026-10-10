<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloneItem extends Model
{
    protected $fillable = ['operation_batch_id', 'category', 'source_id', 'dest_id', 'fingerprint', 'before', 'undone'];

    protected $casts = ['before' => 'array', 'undone' => 'boolean'];
}
