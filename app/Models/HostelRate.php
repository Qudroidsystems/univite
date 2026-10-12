<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HostelRate extends Model
{
    protected $fillable = ['hostel_id', 'academic_session_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];
}
