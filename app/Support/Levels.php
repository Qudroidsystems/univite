<?php

namespace App\Support;

use App\Models\Programme;
use App\Models\StudyCategory;

/**
 * Levels are stored as numbers (100, 200 ...). How they are written (100 Level, Level 1, Part 3 ...)
 * is a per-category format: {LEVEL} = 100, {N} = 1.
 */
class Levels
{
    public const DEFAULT_FORMAT = '{LEVEL} Level';

    public static function label(int $level, ?StudyCategory $category = null): string
    {
        $format = $category?->level_format ?: self::DEFAULT_FORMAT;

        return str_replace(['{LEVEL}', '{N}'], [(string) $level, (string) intdiv($level, 100)], $format);
    }

    /** [level => label] for every level any category or programme can have (at least 100–500). */
    public static function options(): array
    {
        $years = max(5, (int) Programme::max('duration_years'), (int) StudyCategory::max('duration_years'));

        $out = [];
        for ($y = 1; $y <= $years; $y++) {
            $out[$y * 100] = self::label($y * 100);
        }

        return $out;
    }
}
