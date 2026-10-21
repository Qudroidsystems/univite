<?php

namespace App\Data\Results;

/**
 * The marks typed or uploaded for one student: a CA and exam, or (on a sheet with an assessment scheme) a mark per
 * assessment item keyed by item id or code. When items are given they win and the CA and exam are worked out from them.
 * A lecturer may instead give only the overall total: with every CA, exam and assessment mark blank, the total is
 * kept as typed and the assessments are cleared. Blank marks stay null; they are checked when applied.
 */
final readonly class Score
{
    /**
     * @param  array<int|string, mixed>|null  $items
     * @param  bool|null  $absent  true: the student did not sit; false: they did; null: leave as it is
     */
    public function __construct(public mixed $ca = null, public mixed $exam = null, public ?array $items = null, public mixed $total = null, public ?bool $absent = null)
    {
    }

    /** "ABS", "Absent" or "AB" typed or uploaded in place of a mark. */
    public static function isAbsentToken(mixed $v): bool
    {
        return is_string($v) && in_array(strtoupper(trim($v)), ['ABS', 'ABSENT', 'AB'], true);
    }

    /**
     * Marks as typed or uploaded, where "ABS" in any mark marks the student absent (that cell is then blank).
     *
     * @param  array<int|string, mixed>|null  $items
     */
    public static function read(mixed $ca, mixed $exam, ?array $items, mixed $total, mixed $absent = null): self
    {
        $flag = $absent === null || $absent === '' ? null : filter_var($absent, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $clean = function ($v) use (&$flag) {
            if (self::isAbsentToken($v)) {
                $flag = true;

                return null;
            }

            return $v;
        };
        $ca = $clean($ca);
        $exam = $clean($exam);
        $total = $clean($total);
        if ($items !== null) {
            $items = array_map($clean, $items);
        }

        return new self($ca, $exam, $items, $total, $flag);
    }

    /**
     * @param  array<int|string, array{ca?: mixed, exam?: mixed, items?: array, total?: mixed, absent?: mixed}>  $rows  keyed by student id
     * @return array<int, self>
     */
    public static function many(array $rows): array
    {
        $out = [];
        foreach ($rows as $studentId => $r) {
            $items = isset($r['items']) && is_array($r['items']) ? $r['items'] : null;
            $out[(int) $studentId] = self::read($r['ca'] ?? null, $r['exam'] ?? null, $items, $r['total'] ?? null, $r['absent'] ?? null);
        }

        return $out;
    }
}
