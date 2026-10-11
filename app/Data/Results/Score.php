<?php

namespace App\Data\Results;

/** The CA and exam marks typed or uploaded for one student. Blank marks stay null; they are checked when applied. */
final readonly class Score
{
    public function __construct(public mixed $ca = null, public mixed $exam = null)
    {
    }

    /**
     * @param  array<int|string, array{ca?: mixed, exam?: mixed}>  $rows  keyed by student id
     * @return array<int, self>
     */
    public static function many(array $rows): array
    {
        $out = [];
        foreach ($rows as $studentId => $r) {
            $out[(int) $studentId] = new self($r['ca'] ?? null, $r['exam'] ?? null);
        }

        return $out;
    }
}
