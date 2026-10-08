<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A student's standing. The name is editable; the switches decide what the status allows. */
class StudentStatus extends Model
{
    protected $fillable = ['code', 'name', 'badge', 'can_login', 'can_register', 'can_pay', 'is_enrolled', 'is_default', 'sort_order', 'is_active'];

    protected $casts = [
        'can_login' => 'boolean', 'can_register' => 'boolean', 'can_pay' => 'boolean',
        'is_enrolled' => 'boolean', 'is_default' => 'boolean', 'is_active' => 'boolean',
    ];

    public const BADGES = ['success' => 'Green', 'info' => 'Blue', 'warning' => 'Orange', 'danger' => 'Red', 'primary' => 'Indigo', 'secondary' => 'Grey'];

    private static ?array $cache = null;

    /** @return array<string, self> keyed by code */
    public static function map(): array
    {
        return self::$cache ??= static::orderBy('sort_order')->get()->keyBy('code')->all();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function byCode(?string $code): ?self
    {
        return $code ? (self::map()[$code] ?? null) : null;
    }

    /** [code => name] of selectable statuses. */
    public static function options(): array
    {
        return collect(self::map())->where('is_active', true)->map->name->all();
    }

    public static function defaultCode(): string
    {
        return collect(self::map())->firstWhere('is_default', true)?->code ?? 'active';
    }

    /** Codes of statuses that have the given switch on. */
    public static function codesWhere(string $switch): array
    {
        return collect(self::map())->where($switch, true)->keys()->all();
    }

    public static function badgeClass(?string $code): string
    {
        $b = self::byCode($code)?->badge ?? 'secondary';

        return "bg-{$b}-subtle text-{$b}";
    }

    public static function label(?string $code): string
    {
        return self::byCode($code)?->name ?? (string) $code;
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flush());
        static::deleted(fn () => self::flush());
    }
}
