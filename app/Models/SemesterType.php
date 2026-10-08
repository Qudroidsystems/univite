<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The kinds of semester this university runs (First/Second, or Harmattan/Rain/Third ...). */
class SemesterType extends Model
{
    protected $fillable = ['sort_order', 'name', 'is_teaching', 'is_active'];

    protected $casts = ['is_teaching' => 'boolean', 'is_active' => 'boolean'];

    /** [order => name] of the active types. Falls back to the stored name when a type was retired. */
    public static function options(): array
    {
        return static::where('is_active', true)->orderBy('sort_order')->pluck('name', 'sort_order')->all();
    }
}
