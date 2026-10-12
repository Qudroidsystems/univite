<?php

namespace App\Data\Payroll;

/** A list sent to someone outside the portal (bank, PFAs, tax office ...): a heading row and data rows. */
final readonly class Schedule
{
    /**
     * @param  string[]  $head
     * @param  array<int, array<int, string|float|null>>  $rows
     * @param  int[]  $money  columns holding amounts (totalled at the foot)
     */
    public function __construct(public string $title, public array $head, public array $rows, public array $money = [], public ?string $group = null)
    {
    }

    /** @return array<int, float> column => total */
    public function totals(): array
    {
        $t = [];
        foreach ($this->money as $c) {
            $t[$c] = round(array_sum(array_map(fn ($r) => (float) ($r[$c] ?? 0), $this->rows)), 2);
        }

        return $t;
    }

    public function csv(): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, $this->head);
        foreach ($this->rows as $r) {
            fputcsv($h, $r);
        }
        if ($this->money) {
            $foot = array_fill(0, count($this->head), '');
            $foot[0] = 'Total';
            foreach ($this->totals() as $c => $v) {
                $foot[$c] = number_format($v, 2, '.', '');
            }
            fputcsv($h, $foot);
        }
        rewind($h);

        return stream_get_contents($h);
    }
}
