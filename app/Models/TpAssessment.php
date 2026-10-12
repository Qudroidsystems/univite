<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TpAssessment extends Model
{
    protected $fillable = ['tp_posting_id', 'visit_no', 'supervisor_id', 'visited_on', 'lesson_topic', 'scores', 'percent', 'remarks'];

    protected $casts = ['scores' => 'array', 'visited_on' => 'date', 'percent' => 'decimal:2'];

    public function posting(): BelongsTo
    {
        return $this->belongsTo(TpPosting::class, 'tp_posting_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }
}
