<?php

namespace App\Data\Matric;

/** Numbers applied from an external list, and the rows that were skipped with why. */
final readonly class MatricImportResult
{
    /** @param  string[]  $skipped */
    public function __construct(public int $applied, public array $skipped, public string $batch)
    {
    }
}
