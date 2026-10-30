<?php

namespace App\Data\Imports;

/** What checking a students or staff spreadsheet found: rows ready to add, and why the others are left out. */
final readonly class PeopleImportCheck
{
    /**
     * @param  array<int, array<string, mixed>>  $good  row number => cleaned values ready to save
     * @param  array<int, string>  $problems  row number => why the row is left out
     */
    public function __construct(public int $rows, public array $good, public array $problems, public int $existing = 0)
    {
    }

    public function toArray(): array
    {
        return ['rows' => $this->rows, 'ready' => count($this->good), 'existing' => $this->existing, 'problems' => $this->problems,
            'sample' => array_slice(array_values($this->good), 0, 8)];
    }
}
