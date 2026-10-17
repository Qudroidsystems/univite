<?php

namespace App\Services\Setup\Sections;

use App\Data\Setup\RestorePlan;
use App\Models\AcademicSession;
use App\Models\CourseAssignment;
use App\Models\ResultSheet;
use App\Services\Setup\RowSync;
use App\Services\Setup\SetupSection;

/** Which lecturer takes which course each semester of the session (Define Courses). */
class LecturersSection implements SetupSection
{
    public function key(): string
    {
        return 'assignments';
    }

    public function label(): string
    {
        return 'Course lecturers';
    }

    public function about(): string
    {
        return 'Which lecturer takes which course in each semester of the session.';
    }

    public function perSession(): bool
    {
        return true;
    }

    private function sync(AcademicSession $session): RowSync
    {
        return new RowSync(CourseAssignment::class, fn () => CourseAssignment::where('academic_session_id', $session->id),
            ['user_id', 'course_id', 'academic_session_id', 'semester_type_id'],
            fn (CourseAssignment $a) => ResultSheet::where('course_id', $a->course_id)->where('academic_session_id', $a->academic_session_id)
                ->where('semester_type_id', $a->semester_type_id)->exists() ? 'A lecturer defined since has a score sheet started, so the definition was kept.' : null);
    }

    public function capture(?AcademicSession $session): array
    {
        return $session ? $this->sync($session)->capture() : [];
    }

    public function count(array $data): int
    {
        return count($data);
    }

    public function restore(array $data, ?AcademicSession $session, bool $apply): RestorePlan
    {
        return $session ? $this->sync($session)->restore($data, $apply) : new RestorePlan();
    }
}
