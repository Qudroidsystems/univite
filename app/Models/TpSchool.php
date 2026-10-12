<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A partner school that takes student teachers. */
class TpSchool extends Model
{
    public const CATEGORIES = ['primary' => 'Primary', 'secondary' => 'Secondary', 'tertiary' => 'Tertiary / college', 'other' => 'Other'];

    protected $fillable = ['name', 'category', 'address', 'town', 'lga', 'state', 'phone', 'head_name', 'default_capacity', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function place(): string
    {
        return collect([$this->town, $this->lga, $this->state])->filter()->unique()->implode(', ');
    }
}
