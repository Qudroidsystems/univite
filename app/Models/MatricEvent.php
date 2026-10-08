<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatricEvent extends Model
{
    protected $fillable = ['student_id', 'action', 'matric_number', 'batch', 'performed_by', 'note'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
