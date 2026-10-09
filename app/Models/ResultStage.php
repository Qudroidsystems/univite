<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResultStage extends Model
{
    protected $fillable = ['name', 'office_role', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** @return \Illuminate\Support\Collection<int, self> active stages in order */
    public static function chain()
    {
        return static::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }
}
