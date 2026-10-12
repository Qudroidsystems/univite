<?php

namespace App\Data\Api;

use App\Models\Timetable;
use App\Models\TimetableEntry;

/** One weekly class on a lecture timetable. */
final readonly class ApiClass
{
    public function __construct(
        public int $id, public int $day, public string $dayName, public string $startsAt, public string $endsAt,
        public ?string $code, public ?string $title, public ?string $venue, public ?string $group,
    ) {
    }

    public static function from(TimetableEntry $e): self
    {
        return new self($e->id, (int) $e->day, Timetable::DAYS[$e->day] ?? '', substr((string) $e->starts_at, 0, 5), substr((string) $e->ends_at, 0, 5),
            $e->course?->code, $e->course?->title, $e->venue?->code, $e->item?->group_label);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'day' => $this->day, 'day_name' => $this->dayName, 'starts_at' => $this->startsAt, 'ends_at' => $this->endsAt,
            'course_code' => $this->code, 'course_title' => $this->title, 'venue' => $this->venue, 'group' => $this->group,
        ];
    }
}
