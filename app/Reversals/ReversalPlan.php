<?php

namespace App\Reversals;

/**
 * What a reversal would do, worked out without changing anything: one line per affected record with its value now and
 * after, and whether it will change, be skipped (already changed since) or is blocked (something depends on it).
 */
class ReversalPlan
{
    /** @var array<int, array{label: string, from: ?string, to: ?string, outcome: string, why: ?string, key: mixed}> */
    public array $items = [];

    /** @var string[] reasons the whole reversal cannot go ahead */
    public array $blockers = [];

    /** @var string[] things to know before confirming */
    public array $warnings = [];

    /** @param array<string, array{0: string, 1: string}> $modes key => [button label, what it does] */
    public function __construct(public string $mode, public array $modes, public string $outcome = '')
    {
    }

    public function change(string $label, ?string $from, ?string $to, mixed $key = null, ?string $why = null): void
    {
        $this->items[] = ['label' => $label, 'from' => $from, 'to' => $to, 'outcome' => 'change', 'why' => $why, 'key' => $key];
    }

    public function skip(string $label, ?string $from, string $why, mixed $key = null): void
    {
        $this->items[] = ['label' => $label, 'from' => $from, 'to' => null, 'outcome' => 'skip', 'why' => $why, 'key' => $key];
    }

    /** Depends on something that a direct reversal would damage. */
    public function block(string $label, ?string $from, string $why, mixed $key = null): void
    {
        $this->items[] = ['label' => $label, 'from' => $from, 'to' => null, 'outcome' => 'blocked', 'why' => $why, 'key' => $key];
    }

    /** @return array{change: int, skip: int, blocked: int} */
    public function counts(): array
    {
        $c = ['change' => 0, 'skip' => 0, 'blocked' => 0];
        foreach ($this->items as $i) {
            $c[$i['outcome']]++;
        }

        return $c;
    }

    /** @return array<int, array> the lines that will change */
    public function changes(): array
    {
        return array_values(array_filter($this->items, fn ($i) => $i['outcome'] === 'change'));
    }

    public function canRun(): bool
    {
        return ! $this->blockers && $this->counts()['change'] > 0;
    }

    /** Fingerprint of the preview, so the confirmation is refused if anything changed after it was shown. */
    public function fingerprint(): string
    {
        return md5($this->mode . '|' . json_encode(array_map(fn ($i) => [$i['label'], $i['from'], $i['to'], $i['outcome']], $this->items)) . '|' . json_encode($this->blockers));
    }
}
