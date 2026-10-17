<?php

namespace App\Data\Academics;

use App\Models\Department;

/** A department with its faculty, head of department and dean, for the strip on lecturers' result pages. */
final readonly class DepartmentLeaders
{
    public function __construct(
        public string $department,
        public ?string $faculty,
        public ?Leader $hod,
        public ?Leader $dean,
    ) {
    }

    public static function of(?Department $d): ?self
    {
        if (! $d) {
            return null;
        }
        $d->loadMissing('hod.staffProfile', 'faculty.dean.staffProfile');

        return new self($d->name, $d->faculty?->name, Leader::of('Head of Department', $d->hod), Leader::of('Dean', $d->faculty?->dean));
    }
}
