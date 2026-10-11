<?php

namespace App\Data\Legacy;

/** A course the old records use that the portal does not have yet, to be added with the import. */
final readonly class NewCourse
{
    public function __construct(public string $key, public string $code, public string $title, public int $units, public int $departmentId)
    {
    }

    public function label(): string
    {
        return "{$this->code} · {$this->title} ({$this->units} units)";
    }
}
