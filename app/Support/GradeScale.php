<?php

namespace App\Support;

use App\Models\CourseResult;
use App\Models\DegreeClass;
use App\Models\GradeBand;
use Illuminate\Support\Collection;

/** The university's grading scale and the GPA / class-of-degree arithmetic built on it. */
class GradeScale
{
    private static ?Collection $bands = null;

    /** @return Collection<int, GradeBand> highest band first */
    public static function bands(): Collection
    {
        return self::$bands ??= GradeBand::orderByDesc('min_score')->get();
    }

    public static function flush(): void
    {
        self::$bands = null;
    }

    /** @return array{grade: string, points: float, is_pass: bool}|null null when the score falls in no band */
    public static function grade(?float $score): ?array
    {
        if ($score === null) {
            return null;
        }
        $score = round($score, 2);
        $band = self::bands()->first(fn (GradeBand $b) => $score >= $b->min_score && $score <= $b->max_score);

        return $band ? ['grade' => $band->letter, 'points' => $band->points, 'is_pass' => $band->is_pass] : null;
    }

    /** Highest points any band gives, e.g. 5 on a 5-point scale. */
    public static function maxPoints(): float
    {
        return (float) (self::bands()->max('points') ?: 0);
    }

    /**
     * GPA over results: sum(points x units) / sum(units). Results with no grade are ignored.
     *
     * @param  iterable<CourseResult>  $results
     */
    public static function gpa(iterable $results): float
    {
        $units = 0;
        $weighted = 0.0;
        foreach ($results as $r) {
            if ($r->grade === null || $r->points === null) {
                continue;
            }
            $units += $r->credit_units;
            $weighted += $r->points * $r->credit_units;
        }

        return $units > 0 ? round($weighted / $units, 2) : 0.0;
    }

    public static function classOf(float $cgpa): ?string
    {
        return DegreeClass::orderByDesc('min_cgpa')->get()->first(fn ($c) => $cgpa + 0.0001 >= $c->min_cgpa)?->name;
    }
}
