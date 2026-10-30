<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A specialisation inside a programme, e.g. Acting or Directing in Theatre Arts. */
class CourseOption extends Model
{
    protected $fillable = ['programme_id', 'name', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }
}
