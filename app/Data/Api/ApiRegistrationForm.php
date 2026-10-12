<?php

namespace App\Data\Api;

use App\Models\RegistrationForm;

/** A course registration form and the courses on it. */
final readonly class ApiRegistrationForm
{
    /** @param  array<int, array<string, mixed>>  $courses */
    public function __construct(
        public int $id, public ?string $session, public ?string $semester, public ?int $level, public string $status,
        public int $totalUnits, public ?string $note, public ?string $decidedAt, public array $courses,
    ) {
    }

    public static function from(RegistrationForm $f): self
    {
        $f->loadMissing('session', 'semesterType', 'courses.course');

        return new self($f->id, $f->session?->name, $f->semesterType?->name, $f->level, $f->status, (int) $f->total_units, $f->note,
            $f->decided_at?->toIso8601String(), $f->courses->map(fn ($c) => [
                'id' => $c->course_id, 'code' => $c->course?->code, 'title' => $c->course?->title, 'units' => (int) $c->credit_units,
                'status' => $c->course_status, 'carryover' => (bool) $c->is_carryover,
            ])->values()->all());
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'session' => $this->session, 'semester' => $this->semester, 'level' => $this->level, 'status' => $this->status,
            'total_units' => $this->totalUnits, 'note' => $this->note, 'decided_at' => $this->decidedAt, 'courses' => $this->courses,
        ];
    }
}
