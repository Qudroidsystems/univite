<?php

namespace App\Data\Accommodation;

/** A run of rooms to add at once: block A, rooms 101 to 120, four beds each. */
final readonly class RoomBatch
{
    public function __construct(public ?string $block, public int $from, public int $to, public int $capacity, public ?int $floor, public string $prefix = '')
    {
    }

    public static function rules(): array
    {
        return ['block' => ['nullable', 'string', 'max:50'], 'from' => ['required', 'integer', 'min:0', 'max:99999'], 'to' => ['required', 'integer', 'gte:from', 'max:99999'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'], 'floor' => ['nullable', 'integer', 'min:0', 'max:100'], 'prefix' => ['nullable', 'string', 'max:10']];
    }

    public static function fromArray(array $d): self
    {
        return new self(($d['block'] ?? null) ?: null, (int) $d['from'], (int) $d['to'], (int) $d['capacity'], isset($d['floor']) && $d['floor'] !== '' ? (int) $d['floor'] : null, (string) ($d['prefix'] ?? ''));
    }

    /** @return string[] */
    public function names(): array
    {
        return array_map(fn ($n) => $this->prefix . $n, range($this->from, min($this->to, $this->from + 499)));
    }
}
