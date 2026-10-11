<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/** Public holidays and closure days. Leave does not count them as working days and timetables do not use them. */
class Holiday extends Model
{
    protected $fillable = ['date', 'name', 'recurring'];

    protected $casts = ['date' => 'date', 'recurring' => 'boolean'];

    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flush());
        static::deleted(fn () => self::flush());
    }

    public static function nameOn(CarbonInterface $date): ?string
    {
        self::$cache ??= static::all()->map(fn ($h) => [$h->date->toDateString(), $h->date->format('m-d'), $h->recurring, $h->name])->all();
        foreach (self::$cache as [$d, $md, $recurring, $name]) {
            if ($d === $date->toDateString() || ($recurring && $md === $date->format('m-d'))) {
                return $name;
            }
        }

        return null;
    }
}
