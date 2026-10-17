<?php

namespace App\Support;

use App\Data\Results\KeyContext;
use App\Models\CourseResult;
use App\Models\DegreeClass;
use App\Models\GradeBand;
use Illuminate\Support\Collection;

/**
 * The key to grade (grading scale) and the GPA / class-of-degree arithmetic built on it. A school, department or
 * student category may have its own key; without a context the university-wide key is used.
 */
class GradeScale
{
    private static ?Collection $all = null;

    /** @var array<string, Collection<int, GradeBand>> */
    private static array $picked = [];

    /** @return Collection<int, GradeBand> the key that applies in $context (the university's by default), highest band first */
    public static function bands(?KeyContext $context = null): Collection
    {
        $context ??= new KeyContext;
        $all = self::$all ??= GradeBand::orderByDesc('min_score')->get();

        return self::$picked["{$context->facultyId}:{$context->departmentId}:{$context->categoryId}"] ??= GradeBand::pick($all, $context);
    }

    public static function flush(): void
    {
        self::$all = null;
        self::$picked = [];
    }

    /** @return array{grade: string, points: float, is_pass: bool, rank: ?string}|null null when the score falls in no band */
    public static function grade(?float $score, ?KeyContext $context = null): ?array
    {
        if ($score === null) {
            return null;
        }
        $score = round($score, 2);
        $band = self::bands($context)->first(fn (GradeBand $b) => $score >= $b->min_score && $score <= $b->max_score);

        return $band ? ['grade' => $band->letter, 'points' => $band->points, 'is_pass' => $band->is_pass, 'rank' => $band->rank] : null;
    }

    /** Grade a course result by the key of its course's department and its student's category. */
    public static function gradeResult(CourseResult $row, ?float $score): ?array
    {
        return self::grade($score, KeyContext::forResult($row));
    }

    /** Highest points any band gives, e.g. 5 on a 5-point scale. */
    public static function maxPoints(?KeyContext $context = null): float
    {
        return (float) (self::bands($context)->max('points') ?: 0);
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
