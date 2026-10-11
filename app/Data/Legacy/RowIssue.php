<?php

namespace App\Data\Legacy;

/** A problem (keeps the row out) or a warning (does not) about one row of the file. */
final readonly class RowIssue
{
    public function __construct(public int $row, public string $matric, public string $message)
    {
    }

    public static function fromArray(array $a): self
    {
        return new self((int) $a[0], (string) $a[1], (string) $a[2]);
    }

    public function toArray(): array
    {
        return [$this->row, $this->matric, $this->message];
    }
}
