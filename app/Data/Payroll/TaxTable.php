<?php

namespace App\Data\Payroll;

/** Graduated yearly income tax bands, lowest first. */
final readonly class TaxTable
{
    /** @param  TaxBand[]  $bands */
    public function __construct(public array $bands)
    {
    }

    /** @param  array<int, array{upto: mixed, rate: mixed}>  $rows */
    public static function fromArray(array $rows): self
    {
        $bands = [];
        foreach ($rows as $r) {
            $upto = isset($r['upto']) && $r['upto'] !== '' && $r['upto'] !== null ? (float) $r['upto'] : null;
            $bands[] = new TaxBand($upto, (float) ($r['rate'] ?? 0));
        }
        usort($bands, fn ($a, $b) => ($a->upto ?? INF) <=> ($b->upto ?? INF));

        return new self($bands);
    }

    public function toArray(): array
    {
        return array_map(fn (TaxBand $b) => ['upto' => $b->upto, 'rate' => $b->rate], $this->bands);
    }

    /** Tax on a year's taxable income. */
    public function annualTax(float $income): float
    {
        $tax = 0.0;
        $from = 0.0;
        foreach ($this->bands as $b) {
            if ($income <= $from) {
                break;
            }
            $to = $b->upto === null ? $income : min($income, $b->upto);
            $tax += max(0, $to - $from) * $b->rate / 100;
            if ($b->upto === null) {
                break;
            }
            $from = $b->upto;
        }

        return round($tax, 2);
    }
}
