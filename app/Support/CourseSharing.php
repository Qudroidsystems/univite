<?php

namespace App\Support;

use App\Models\Course;
use App\Models\Programme;

/**
 * Who may put a course on a curriculum, and as what. The department that owns a course decides its type for its own
 * programmes and whether other departments may take it:
 *  - its own programmes may give it any type (compulsory, compulsory elective, elective);
 *  - other departments may only take it the way the owning department shares it (as an elective, a compulsory
 *    elective, either of the two, or, for a general course such as GST, any type);
 *  - a course that is not shared stays within its department.
 */
final class CourseSharing
{
    public const NONE = '';

    public const ELECTIVE = 'elective';

    public const COMPULSORY_ELECTIVE = 'compulsory_elective';

    public const EITHER = 'either';

    public const GENERAL = 'general';

    /** @return array<string, string> the choices on the course form */
    public static function options(): array
    {
        $e = CourseType::label(CourseType::ELECTIVE) ?: 'Elective';
        $ce = CourseType::label(CourseType::COMPULSORY_ELECTIVE) ?: 'Compulsory elective';

        return [
            self::NONE => 'Only this department\'s programmes',
            self::ELECTIVE => "Other departments may take it as an {$e}",
            self::COMPULSORY_ELECTIVE => "Other departments may take it as a {$ce}",
            self::EITHER => "Other departments may take it as an {$e} or a {$ce}",
            self::GENERAL => 'General course: any department may make it compulsory, a compulsory elective or an elective',
        ];
    }

    /** Short words for lists: "E", "CE", "E or CE", "Any". */
    public static function short(?string $sharedAs): string
    {
        return match ($sharedAs) {
            self::ELECTIVE => 'E',
            self::COMPULSORY_ELECTIVE => 'CE',
            self::EITHER => 'E or CE',
            self::GENERAL => 'Any type',
            default => '',
        };
    }

    /** The types other departments may give a course shared this way. @return string[] */
    public static function typesFor(?string $sharedAs): array
    {
        return match ($sharedAs) {
            self::ELECTIVE => [CourseType::ELECTIVE],
            self::COMPULSORY_ELECTIVE => [CourseType::COMPULSORY_ELECTIVE],
            self::EITHER => [CourseType::COMPULSORY_ELECTIVE, CourseType::ELECTIVE],
            self::GENERAL => [CourseType::COMPULSORY, CourseType::COMPULSORY_ELECTIVE, CourseType::ELECTIVE],
            default => [],
        };
    }

    public static function isOwn(Course $course, Programme $programme): bool
    {
        return (int) $course->department_id === (int) $programme->department_id;
    }

    /** The types a programme may give a course. @return string[] (empty: the programme may not take it) */
    public static function allowed(Course $course, Programme $programme): array
    {
        return self::isOwn($course, $programme)
            ? [CourseType::COMPULSORY, CourseType::COMPULSORY_ELECTIVE, CourseType::ELECTIVE]
            : self::typesFor($course->shared_as);
    }

    /** The type a course gets when none is chosen: its own type where allowed, else the first allowed. */
    public static function defaultType(Course $course, Programme $programme): ?string
    {
        $allowed = self::allowed($course, $programme);
        if (! $allowed) {
            return null;
        }
        $own = $course->course_type ?: CourseType::COMPULSORY;

        return in_array($own, $allowed, true) ? $own : (in_array(CourseType::ELECTIVE, $allowed, true) ? CourseType::ELECTIVE : $allowed[0]);
    }

    /** Why a programme may not give a course a type, or null when it may. */
    public static function problem(Course $course, Programme $programme, string $type): ?string
    {
        $allowed = self::allowed($course, $programme);
        if (in_array($type, $allowed, true)) {
            return null;
        }
        $owner = $course->department?->name ?? 'its department';
        if (! $allowed) {
            return "{$course->code} belongs to {$owner}, which has not opened it to other departments.";
        }

        return "{$course->code} belongs to {$owner}, which lets other departments take it only as "
            . collect($allowed)->map(fn ($t) => strtolower(CourseType::label($t)))->implode(' or ') . '.';
    }
}
