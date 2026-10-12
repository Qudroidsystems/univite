<?php

namespace App\Data\Results;

/**
 * The marks typed or uploaded for one student: a CA and exam, or (on a sheet with an assessment scheme) a mark per
 * assessment item keyed by item id or code. When items are given they win and the CA and exam are worked out from them.
 * Blank marks stay null; they are checked when applied.
 */
final readonly class Score
{
    /** @param  array<int|string, mixed>|null  $items */
    public function __construct(public mixed $ca = null, public mixed $exam = null, public ?array $items = null)
    {
    }

    /**
     * @param  array<int|string, array{ca?: mixed, exam?: mixed, items?: array}>  $rows  keyed by student id
     * @return array<int, self>
     */
    public static function many(array $rows): array
    {
        $out = [];
        foreach ($rows as $studentId => $r) {
            $items = isset($r['items']) && is_array($r['items']) ? $r['items'] : null;
            $out[(int) $studentId] = new self($r['ca'] ?? null, $r['exam'] ?? null, $items);
        }

        return $out;
    }
}
