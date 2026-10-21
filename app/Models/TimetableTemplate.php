<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A saved set of wizard answers (day structure, rules, priorities, requirements by units), reusable each semester. */
class TimetableTemplate extends Model
{
    protected $fillable = ['name', 'description', 'settings', 'created_by'];

    protected $casts = ['settings' => 'array'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
