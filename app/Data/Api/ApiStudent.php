<?php

namespace App\Data\Api;

use App\Models\Student;

/** A student as the mobile app sees them. */
final readonly class ApiStudent
{
    public function __construct(
        public int $id, public ?string $matricNumber, public string $name, public ?string $programme, public ?string $department,
        public ?string $faculty, public ?int $level, public ?string $entrySession, public ?string $status, public ?string $photoUrl,
    ) {
    }

    public static function from(Student $s): self
    {
        $s->loadMissing('programme.department.faculty', 'entrySession');

        return new self($s->id, $s->matric_number ?: $s->provisional_matric, $s->full_name, $s->programme?->name, $s->programme?->department?->name,
            $s->programme?->department?->faculty?->name, $s->current_level, $s->entrySession?->name, $s->status, $s->photo_url);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'matric_number' => $this->matricNumber, 'name' => $this->name, 'programme' => $this->programme,
            'department' => $this->department, 'faculty' => $this->faculty, 'level' => $this->level, 'entry_session' => $this->entrySession,
            'status' => $this->status, 'photo_url' => $this->photoUrl,
        ];
    }
}
