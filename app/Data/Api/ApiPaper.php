<?php

namespace App\Data\Api;

use App\Models\ExamSeat;
use App\Models\TimetableEntry;

/** One examination paper, with the student's hall and seat once seating is published. */
final readonly class ApiPaper
{
    public function __construct(
        public int $id, public ?string $date, public string $startsAt, public string $endsAt, public ?string $code, public ?string $title,
        public array $venues, public ?array $seat, public array $locations = [], public ?int $courseId = null,
    ) {
    }

    /** @param  iterable<TimetableEntry>  $rows  the rooms one paper is spread over */
    public static function from(iterable $rows, ?ExamSeat $seat = null): self
    {
        $rows = collect($rows);
        $first = $rows->first();

        return new self($first->id, $first->date?->toDateString(), substr((string) $first->starts_at, 0, 5), substr((string) $first->ends_at, 0, 5),
            $first->course?->code, $first->course?->title, $rows->map(fn ($r) => $r->venue?->code)->filter()->unique()->values()->all(),
            $seat ? ['hall' => $seat->venue?->code, 'seat' => $seat->seat_label, 'code' => $seat->code, 'location' => $seat->venue?->location()] : null,
            $rows->map(fn ($r) => $r->venue?->location())->filter()->unique('code')->values()->all(), $first->course_id);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'date' => $this->date, 'starts_at' => $this->startsAt, 'ends_at' => $this->endsAt, 'course_code' => $this->code,
            'course_id' => $this->courseId, 'course_title' => $this->title, 'venues' => $this->venues, 'seat' => $this->seat, 'locations' => $this->locations,
        ];
    }
}
