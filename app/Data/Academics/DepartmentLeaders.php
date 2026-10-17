<?php

namespace App\Data\Academics;

use App\Models\Department;
use App\Services\Access\HeadRoles;

/** A department with its faculty, head of department and dean, for the strip on lecturers' result pages. */
final readonly class DepartmentLeaders
{
    public function __construct(
        public string $department,
        public ?string $faculty,
        public ?Leader $hod,
        public ?Leader $dean,
        public string $hodTitle = 'Head of Department',
        public string $deanTitle = 'Dean',
    ) {
    }

    public static function of(?Department $d): ?self
    {
        if (! $d) {
            return null;
        }
        $d->loadMissing('hod.staffProfile', 'faculty.dean.staffProfile');

        // The titles are the head roles' names (Scoped Roles & Heads settings), so a university's own names show.
        $heads = app(HeadRoles::class);
        $hodTitle = $heads->roleName('department') ?? 'Head of Department';
        $deanTitle = $heads->roleName('faculty') ?? 'Dean';

        return new self($d->name, $d->faculty?->name, Leader::of($hodTitle, $d->hod), Leader::of($deanTitle, $d->faculty?->dean), $hodTitle, $deanTitle);
    }
}
