<?php

namespace App\Data\Legacy;

/** Which column of the uploaded file holds each field (field => column index). */
final readonly class ColumnMapping
{
    /** @param array<string, int> $columns */
    public function __construct(public array $columns = [])
    {
    }

    /** Keep only fields mapped to a column that exists in the file. */
    public static function fromArray(?array $a, array $headers = []): self
    {
        $out = [];
        foreach ($a ?? [] as $field => $index) {
            if ($index === null || $index === '' || ! is_numeric($index) || ($headers && ! array_key_exists((int) $index, $headers))) {
                continue;
            }
            $out[(string) $field] = (int) $index;
        }

        return new self($out);
    }

    public function has(string $field): bool
    {
        return isset($this->columns[$field]);
    }

    /** The trimmed text of a field in one row of the file, '' when the field is not mapped or the cell is empty. */
    public function value(array $line, string $field): string
    {
        return isset($this->columns[$field]) ? trim((string) ($line[$this->columns[$field]] ?? '')) : '';
    }

    public function toArray(): array
    {
        return $this->columns;
    }
}
