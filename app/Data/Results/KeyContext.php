<?php

namespace App\Data\Results;

use App\Models\Course;
use App\Models\CourseResult;
use App\Models\Student;

/** Where a result belongs, for choosing the key to grade and key to result: the school and department, and the student's category. */
final readonly class KeyContext
{
    public function __construct(public ?int $facultyId = null, public ?int $departmentId = null, public ?int $categoryId = null)
    {
    }

    /** A course result is graded by the key of the course's department, for the student's category. */
    public static function forResult(CourseResult $row): self
    {
        $row->loadMissing('course.department', 'student.programme');

        return self::of($row->course, $row->student);
    }

    public static function of(?Course $course, ?Student $student): self
    {
        $dept = $course?->department ?? $student?->programme?->department;

        return new self($dept?->faculty_id, $dept?->id, $student?->programme?->study_category_id);
    }

    /** A student's standing uses their own programme's department. */
    public static function forStudent(Student $student): self
    {
        $student->loadMissing('programme.department');
        $dept = $student->programme?->department;

        return new self($dept?->faculty_id, $dept?->id, $student->programme?->study_category_id);
    }
}
