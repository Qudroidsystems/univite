<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TpSupervisor extends Model
{
    protected $fillable = ['tp_exercise_id', 'tp_school_id', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(TpSchool::class, 'tp_school_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(TpExercise::class, 'tp_exercise_id');
    }
}
