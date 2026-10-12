<?php

namespace App\Data\Api;

use App\Models\User;

/** The signed-in person: who they are, their roles, and their student record if they are a student. */
final readonly class ApiUser
{
    /** @param  string[]  $roles */
    public function __construct(
        public int $id, public string $name, public ?string $email, public ?string $phone, public string $avatarUrl,
        public array $roles, public ?ApiStudent $student, public bool $notifyPush, public bool $teaches = false, public ?string $photoUrl = null,
    ) {
    }

    public static function from(User $u): self
    {
        return new self($u->id, $u->name, $u->email, $u->phone_number, $u->avatar_url, $u->getRoleNames()->values()->all(),
            $u->student ? ApiStudent::from($u->student) : null, (bool) ($u->notify_push ?? true),
            ! $u->student && ($u->courseAssignments()->exists() || $u->can('Enter results for any course')), $u->photoUrl());
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'phone' => $this->phone, 'avatar_url' => $this->avatarUrl, 'photo_url' => $this->photoUrl,
            'roles' => $this->roles, 'type' => $this->student ? 'student' : 'staff', 'student' => $this->student?->toArray(), 'notify_push' => $this->notifyPush,
            'teaches' => $this->teaches,
        ];
    }
}
