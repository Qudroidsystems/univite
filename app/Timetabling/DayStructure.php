<?php

namespace App\Timetabling;

/**
 * Builds a teaching day from a few numbers, as the generation wizard asks for them: when the day starts and ends, how
 * long a period is, and where the breaks fall ("a 30-minute break after period 2, a 60-minute lunch after period 4").
 * Periods are laid end to end; a break leaves a gap, so a double period never runs across it. Pure: no database.
 */
final class DayStructure
{
    /**
     * @param  array<int, array{after: int, minutes: int, label?: string}>  $breaks
     * @return array{periods: array<int, array{0: string, 1: string}>, breaks: array<int, array{0: string, 1: string, 2: string}>}
     */
    public static function build(string $start, string $end, int $length, array $breaks = [], ?int $maxPeriods = null): array
    {
        $length = max(10, min(240, $length));
        $at = self::toMinutes($start);
        $stop = self::toMinutes($end);
        $byPeriod = [];
        foreach ($breaks as $b) {
            if ((int) ($b['after'] ?? 0) > 0 && (int) ($b['minutes'] ?? 0) > 0) {
                $byPeriod[(int) $b['after']] = ['minutes' => (int) $b['minutes'], 'label' => trim((string) ($b['label'] ?? '')) ?: 'Break'];
            }
        }
        $periods = $gaps = [];
        $n = 0;
        while ($at + $length <= $stop && ($maxPeriods === null || $n < $maxPeriods) && $n < 24) {
            $periods[] = [self::toTime($at), self::toTime($at + $length)];
            $at += $length;
            $n++;
            if (isset($byPeriod[$n])) {
                $gaps[] = [self::toTime($at), self::toTime(min($stop, $at + $byPeriod[$n]['minutes'])), $byPeriod[$n]['label']];
                $at += $byPeriod[$n]['minutes'];
            }
        }

        return ['periods' => $periods, 'breaks' => array_values(array_filter($gaps, fn ($g) => $g[0] < $g[1]))];
    }

    public static function toMinutes(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', substr($hm, 0, 5)) + [0, 0]);

        return $h * 60 + $m;
    }

    public static function toTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
