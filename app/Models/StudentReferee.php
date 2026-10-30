<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One of a student's two referees (Referee 1, Referee 2 on the old portal's student data). */
class StudentReferee extends Model
{
    protected $fillable = ['student_id', 'position', 'name', 'address', 'phone', 'email', 'occupation'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
