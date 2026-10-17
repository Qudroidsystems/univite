<?php

namespace App\Data\Academics;

use App\Models\User;

/** A head of department or dean as shown on a page: name with title, staff number, designation and picture. */
final readonly class Leader
{
    public function __construct(
        public string $role,
        public string $name,
        public ?string $staffNumber,
        public ?string $designation,
        public ?string $photoUrl,
        public string $initials,
        public ?string $email,
    ) {
    }

    public static function of(string $role, ?User $u): ?self
    {
        if (! $u) {
            return null;
        }
        $p = $u->staffProfile;

        return new self($role, $p ? $p->displayName() : $u->name, $p?->staff_number, $p?->designation, $u->photoUrl(), $u->initials, $u->email);
    }
}
