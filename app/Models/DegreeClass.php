<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DegreeClass extends Model
{
    protected $fillable = ['name', 'min_cgpa'];

    protected $casts = ['min_cgpa' => 'float'];
}
