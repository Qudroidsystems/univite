<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How a candidate enters the university (UTME, Direct Entry, Transfer ...), and what that entails. */
class EntryMode extends Model
{
    protected $fillable = ['code', 'name', 'entry_level', 'fixed_level', 'uses_utme', 'needs_prior_result', 'open_online', 'sort_order', 'is_active'];

    protected $casts = [
        'fixed_level' => 'boolean', 'uses_utme' => 'boolean', 'needs_prior_result' => 'boolean',
        'open_online' => 'boolean', 'is_active' => 'boolean',
    ];

    public static function byCode(?string $code): ?self
    {
        return $code ? static::where('code', $code)->first() : null;
    }

    /** [code => name] */
    public static function options(bool $onlineOnly = false): array
    {
        return static::where('is_active', true)->when($onlineOnly, fn ($q) => $q->where('open_online', true))
            ->orderBy('sort_order')->pluck('name', 'code')->all();
    }

    public static function label(?string $code): string
    {
        return static::byCode($code)?->name ?? (string) $code;
    }
}
