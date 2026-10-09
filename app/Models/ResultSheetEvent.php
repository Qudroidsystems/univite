<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultSheetEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['result_sheet_id', 'user_id', 'action', 'stage', 'note', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
