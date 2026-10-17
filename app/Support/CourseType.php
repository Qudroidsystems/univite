<?php

namespace App\Support;

/**
 * Whether a course is compulsory, a compulsory elective or an elective. A course carries its usual type and each
 * programme's curriculum can set its own (a course compulsory for one programme may be an elective for another).
 *
 *  - Compulsory (C): must be registered in its semester and passed to graduate; failing it makes a carry-over.
 *  - Compulsory elective (CE): the student must take a set number of units from the compulsory electives on offer;
 *    a failed one is retaken (or, if the rules allow, replaced by another compulsory elective).
 *  - Elective (E): optional; a failed one need not be retaken if the rules allow.
 *
 * The names shown are the course_status lookup's labels, so a university can rename them.
 */
final class CourseType
{
    public const COMPULSORY = 'compulsory';

    public const COMPULSORY_ELECTIVE = 'compulsory_elective';

    public const ELECTIVE = 'elective';

    public const DEFAULT_LABELS = [self::COMPULSORY => 'Compulsory', self::COMPULSORY_ELECTIVE => 'Compulsory elective', self::ELECTIVE => 'Elective'];

    public const SHORT = [self::COMPULSORY => 'C', self::COMPULSORY_ELECTIVE => 'CE', self::ELECTIVE => 'E'];

    public const BADGES = [self::COMPULSORY => 'bg-primary-subtle text-primary', self::COMPULSORY_ELECTIVE => 'bg-warning-subtle text-warning', self::ELECTIVE => 'bg-info-subtle text-info'];

    /** @return array<string, string> code => label, in the lookup's order */
    public static function options(): array
    {
        return Lookups::options('course_status') ?: self::DEFAULT_LABELS;
    }

    public static function label(?string $type): string
    {
        return $type ? Lookups::label('course_status', $type) : '';
    }

    public static function short(?string $type): string
    {
        return self::SHORT[$type] ?? '';
    }

    /** Whether a failed course of this type has to be passed after all (a carry-over), under a student's academic rules. */
    public static function mustRetake(?string $type, array $rules): bool
    {
        return match ($type) {
            self::ELECTIVE => (bool) ($rules['retake_failed_electives'] ?? true),
            self::COMPULSORY_ELECTIVE => (bool) ($rules['retake_failed_compulsory_electives'] ?? true),
            default => true,
        };
    }

    public static function isElective(?string $type): bool
    {
        return in_array($type, [self::COMPULSORY_ELECTIVE, self::ELECTIVE], true);
    }
}
