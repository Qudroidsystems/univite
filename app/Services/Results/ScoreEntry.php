<?php

namespace App\Services\Results;

use App\Data\Results\ScoreEntryQuery;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseResult;
use App\Models\ProgrammeCourse;
use App\Models\ResultSheet;
use App\Models\User;
use App\Services\ResultService;
use Illuminate\Support\Collection;

/**
 * Enter Scores: the courses a lecturer may score for a session and semester (narrowed by department, student category
 * and level), the score sheet of the chosen course, and its rows: everyone on the course (post by subject) or only the
 * chosen class (post by student).
 */
class ScoreEntry
{
    public function __construct(private ResultService $results)
    {
    }

    /** Whether this person enters scores at all (assigned courses, or any course). */
    public function canUse(User $user): bool
    {
        return $user->can('Enter results for any course') || $user->courseAssignments()->exists();
    }

    /** @return Collection<int, Course> */
    public function courses(User $user, ScoreEntryQuery $q): Collection
    {
        if (! $q->session || ! $q->semesterType) {
            return collect();
        }
        $courses = $user->can('Enter results for any course')
            ? Course::with('department')->where('is_active', true)
            : Course::with('department')->whereIn('id', CourseAssignment::where('user_id', $user->id)
                ->where('academic_session_id', $q->session->id)->where('semester_type_id', $q->semesterType->id)->select('course_id'));

        if ($q->department || $q->category || $q->level) {
            // In the curriculum of a matching programme at that level and semester, or set up that way on the course itself.
            $taught = ProgrammeCourse::query()->where('semester_order', $q->semesterType->sort_order)
                ->when($q->level, fn ($w, $level) => $w->where('level', $level))
                ->whereHas('programme', fn ($p) => $p
                    ->when($q->department, fn ($w, $d) => $w->where('department_id', $d->id))
                    ->when($q->category, fn ($w, $c) => $w->where('study_category_id', $c->id)))
                ->select('course_id');
            $courses->where(fn ($w) => $w->whereIn('id', $taught)->orWhere(fn ($own) => $own
                ->when($q->department, fn ($x, $d) => $x->where('department_id', $d->id))
                ->when($q->category, fn ($x, $c) => $x->where('study_category_id', $c->id))
                ->when($q->level, fn ($x, $l) => $x->where('level', $l))));
        }

        return $courses->orderBy('code')->get();
    }

    /** The score sheet of the chosen course (opened if needed), or null when it cannot be shown to this person. */
    public function sheet(User $user, ScoreEntryQuery $q): ?ResultSheet
    {
        if ($q->missing() || ! $this->courses($user, $q)->contains('id', $q->course->id)) {
            return null;
        }
        $sheet = $this->results->sheet($q->course->id, $q->session->id, $q->semesterType->id);

        return $this->results->canEnter($user, $sheet) ? $sheet : null;
    }

    /** @return Collection<int, CourseResult> the rows to show, sorted by name */
    public function rows(ResultSheet $sheet, ScoreEntryQuery $q): Collection
    {
        $rows = $sheet->results()->with('student.programme', 'assessmentScores');
        if ($q->byStudent()) {
            $rows->whereHas('student', fn ($s) => $s
                ->whereHas('programme', fn ($p) => $p->where('department_id', $q->department->id)->where('study_category_id', $q->category->id))
                ->whereHas('registrationForms', fn ($f) => $f->where('academic_session_id', $sheet->academic_session_id)
                    ->where('semester_type_id', $sheet->semester_type_id)->where('level', $q->level)->where('status', 'approved')));
        }

        return $rows->get()->sortBy(fn ($r) => [$r->student->last_name, $r->student->first_name])->values();
    }
}
