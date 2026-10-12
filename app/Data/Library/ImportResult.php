<?php

namespace App\Data\Library;

final readonly class ImportResult
{
    /** @param  string[]  $errors */
    public function __construct(public int $titles, public int $copies, public array $errors)
    {
    }
}
