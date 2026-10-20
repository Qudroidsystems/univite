<?php

namespace App\Data\Api;

use App\Models\Course;
use App\Services\Api\CourseOwners;
use App\Support\CourseType;

/**
 * A course as the app shows it, the same way the portal does: its type, the department and faculty that own it, and
 * the people who approve its scores (head of department, dean) with the approval steps a score sheet goes through.
 */
final readonly class ApiCourse
{
    public function __construct(private Course $course, private ?string $type = null)
    {
    }

    public static function from(Course $course, ?string $type = null): self
    {
        return new self($course, $type);
    }

    public function toArray(): array
    {
        $c = $this->course;
        $type = $this->type ?: ($c->course_type ?: CourseType::COMPULSORY);
        $owners = app(CourseOwners::class);

        return [
            'id' => $c->id, 'code' => $c->code, 'title' => $c->title, 'units' => (int) $c->credit_units, 'level' => $c->level ? (int) $c->level : null,
            'status' => $type, 'type' => CourseType::short($type), 'type_label' => CourseType::label($type),
            'department' => $c->department?->name,
            'faculty' => $c->department?->faculty?->name,
            'owner' => $owners->owner($c),
            'approval' => $owners->chain(),
        ];
    }

    /** One of RegistrationService::outstanding()'s rows. */
    public static function outstanding(array $o): array
    {
        return self::from($o['course'], $o['type'])->toArray() + [
            'attempts' => $o['attempts'], 'last_grade' => $o['last_grade'], 'last_total' => $o['last_total'] !== null ? (float) $o['last_total'] : null,
            'last_term' => $o['last_term'], 'curriculum_level' => $o['level'], 'offered_in' => $o['semester'],
            'offered_now' => $o['offered_now'], 'registered_now' => $o['chosen_now'],
        ];
    }
}
