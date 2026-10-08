<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lookup extends Model
{
    protected $fillable = ['list', 'code', 'label', 'sort_order', 'is_active', 'is_system'];

    protected $casts = ['is_active' => 'boolean', 'is_system' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\Lookups::flush());
        static::deleted(fn () => \App\Support\Lookups::flush());
    }
}
