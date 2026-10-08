<?php

namespace App\Support;

use App\Models\Lookup;

/** Reads the editable lists (gender, religion, states ...). */
class Lookups
{
    private static ?array $cache = null;

    private static function all(): array
    {
        return self::$cache ??= Lookup::orderBy('sort_order')->orderBy('label')->get()->groupBy('list')->all();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** [code => label] of the active entries of a list. */
    public static function options(string $list): array
    {
        return collect(self::all()[$list] ?? [])->where('is_active', true)->pluck('label', 'code')->all();
    }

    public static function label(string $list, ?string $code): string
    {
        $row = collect(self::all()[$list] ?? [])->firstWhere('code', $code);

        return $row?->label ?? (string) $code;
    }
}
