<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OlevelRequirementSubject extends Model
{
    public const KINDS = ['required' => 'Required', 'optional' => 'One of (optional group)'];

    protected $fillable = ['olevel_requirement_id', 'subject', 'min_grade', 'kind', 'sort_order'];
}
