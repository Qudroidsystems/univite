<?php

namespace App\Services\Results;

use App\Data\Access\AccessScope;
use App\Data\Results\ComputeCourse;
use App\Data\Results\ComputeOutcome;
use App\Data\Results\ComputeOverview;
use App\Data\Results\ComputeQuery;
use App\Data\Results\KeyContext;
use App\Models\CarryoverWaiver;
use App\Models\ComputedResult;
use App\Models\CourseAssignment;
use App\Models\CourseRegistration;
use App\Models\CourseResult;
use App\Models\Department;
use App\Models\ResultKey;
use App\Models\ResultSheet;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use App\Services\ResultService;
use App\Support\AcademicRules;
use App\Support\GradeScale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compute Results: after the lecturers submit their scores, the Head of Department (or anyone given "Compute results")
 * picks a department, student category, level, session and semester and computes. Computing grades every submitted
 * score again by the key to grade that applies, then works out each student's present, previous and cumulative TCP,
 * TNU and GPA, the semester remark (PASS / REP / NRI / INC), the outstanding courses and the cumulative remark from the
 * key to result. The department master sheet is printed from what was computed.
 */
class ResultComputation
{
    public const PERMISSION = 'Compute results';

    /** Score statuses that count: submitted by the lecturer, or further along. */
    private const COUNTED = ['submitted', 'approved', 'published'];

    public function __construct(private ResultService $results, private ScopedAccess $access, private AcademicRules $rules)
    {
    }

    public function scope(?User $user): AccessScope
    {
        return $this->access->scope($user, self::PERMISSION);
    }

    /** @return Collection<int, Department> departments this person may compute for */
    public function departmentsFor(User $user): Collection
    {
        $scope = $this->scope($user);

        return Department::orderBy('name')->get()->filter(fn ($d) => $scope->coversDepartment($d->id))->values();
    }

    /** @return Collection<int, Student> students of the department (and category) at this level in that semester */
    public function students(ComputeQuery $q): Collection
    {
        $inProgramme = fn ($p) => $p->where('department_id', $q->department->id)
            ->when($q->category, fn ($w) => $w->where('study_category_id', $q->category->id));
        $registered = fn ($f) => $f->where('level', $q->level)->where('academic_session_id', $q->session->id)->where('semester_type_id', $q->semesterType->id);

        return Student::with('programme.department', 'legacyBalance', 'user')->whereHas('programme', $inProgramme)
            ->where(fn ($w) => $w->whereHas('registrationForms', $registered)
                ->orWhere(fn ($n) => $n->where('current_level', $q->level)->where('status', 'active')))
            ->get()->sortBy(fn ($s) => [$s->programme->name, $s->matric_number ?? 'zzz', $s->last_name])->values();
    }

    public function overview(ComputeQuery $q): ComputeOverview
    {
        $students = $this->students($q);
        $ids = $students->pluck('id');
        $registered = CourseRegistration::with('course')->whereIn('student_id', $ids)
            ->whereHas('form', fn ($f) => $f->where('academic_session_id', $q->session->id)->where('semester_type_id', $q->semesterType->id)->where('status', 'approved'))
            ->get();
        $courses = $registered->pluck('course')->filter()->unique('id')->sortBy('code')->values();
        $sheets = ResultSheet::with('stage')->whereIn('course_id', $courses->pluck('id'))
            ->where('academic_session_id', $q->session->id)->where('semester_type_id', $q->semesterType->id)->get()->keyBy('course_id');
        $lecturers = CourseAssignment::with('user.staffProfile')->whereIn('course_id', $courses->pluck('id'))
            ->where('academic_session_id', $q->session->id)->where('semester_type_id', $q->semesterType->id)->get()->groupBy('course_id');
        $submitters = User::with('staffProfile')->whereIn('id', $sheets->pluck('submitted_by')->filter())->get()->keyBy('id');
        $results = CourseResult::with('student', 'course', 'sheet')->whereIn('student_id', $ids)
            ->where('academic_session_id', $q->session->id)->where('semester_type_id', $q->semesterType->id)->get();

        $rows = $courses->map(function ($course) use ($sheets, $lecturers, $submitters, $results, $registered) {
            $sheet = $sheets->get($course->id);
            $mine = $results->where('course_id', $course->id)->whereNotNull('total');

            return new ComputeCourse(
                course: $course,
                sheet: $sheet,
                lecturers: $lecturers->get($course->id, collect())->pluck('user')->filter()->values(),
                submittedBy: $sheet?->submitted_by ? $submitters->get($sheet->submitted_by) : null,
                students: $registered->where('course_id', $course->id)->count(),
                scored: $mine->count(),
                passed: $mine->where('is_pass', true)->count(),
                failed: $mine->where('is_pass', false)->count(),
                average: $mine->isEmpty() ? null : round((float) $mine->avg('total'), 1),
            );
        });

        $shown = $results->when($q->course, fn ($r) => $r->where('course_id', $q->course->id))
            ->sortBy(fn ($r) => [$r->course->code, $r->student->matric_number ?? 'zzz'])->values();
        $computed = ComputedResult::with('computedBy.staffProfile', 'resultKey')->whereIn('student_id', $ids)
            ->where('academic_session_id', $q->session->id)->where('semester_type_id', $q->semesterType->id)->get()->keyBy('student_id');

        return new ComputeOverview($q, $students, $rows, $shown, $computed);
    }

    /** Grade the submitted scores by the key to grade, then compute every student's semester result. */
    public function compute(ComputeQuery $q, User $user): ComputeOutcome
    {
        $overview = $this->overview($q);
        $students = $overview->students;
        $ids = $students->pluck('id');
        $regraded = 0;
        $skipped = 0;

        DB::transaction(function () use ($q, $user, $students, $ids, &$regraded, &$skipped) {
            // 1. Key to grade: every counted score of this semester, of the chosen course or all of them.
            $term = CourseResult::with('course.department', 'student.programme')->whereIn('student_id', $ids)
                ->where('academic_session_id', $q->session->id)->where('semester_type_id', $q->semesterType->id)
                ->whereIn('status', self::COUNTED)->whereNotNull('total')
                ->when($q->course, fn ($r) => $r->where('course_id', $q->course->id))->get();
            foreach ($term as $row) {
                $g = GradeScale::gradeResult($row, $row->total);
                if (! $g || ($row->grade === $g['grade'] && (float) $row->points === (float) $g['points'] && $row->is_pass === $g['is_pass'])) {
                    continue;
                }
                if ($row->status === 'published') {   // students have seen it: a change goes through a correction instead
                    $skipped++;
                    continue;
                }
                $row->update(['grade' => $g['grade'], 'points' => $g['points'], 'is_pass' => $g['is_pass']]);
                $regraded++;
            }

            // 2. Each student's semester result.
            $now = now();
            foreach ($this->summaries($q, $students) as $studentId => $values) {
                ComputedResult::updateOrCreate(
                    ['student_id' => $studentId, 'academic_session_id' => $q->session->id, 'semester_type_id' => $q->semesterType->id],
                    $values + ['computed_by' => $user->id, 'computed_at' => $now],
                );
            }
        });

        return new ComputeOutcome($students->count(), $regraded, $skipped, $overview->pending()->count());
    }

    /** @return array<int, array<string, mixed>> student id => computed_results columns */
    private function summaries(ComputeQuery $q, Collection $students): array
    {
        $ids = $students->pluck('id');
        $order = fn ($r) => [$r->session->name, $r->semesterType->sort_order];
        $here = [$q->session->name, $q->semesterType->sort_order];
        $history = CourseResult::with('course', 'session', 'semesterType')->whereIn('student_id', $ids)->whereIn('status', self::COUNTED)->get()
            ->filter(fn ($r) => $order($r) <= $here)->groupBy('student_id');
        $forms = \App\Models\RegistrationForm::with('courses')->whereIn('student_id', $ids)->where('academic_session_id', $q->session->id)
            ->where('semester_type_id', $q->semesterType->id)->where('status', 'approved')->get()->keyBy('student_id');
        $waivers = CarryoverWaiver::active()->whereIn('student_id', $ids)->get()->groupBy('student_id');
        $keys = ResultKey::all();
        $perSession = max(1, SemesterType::count());
        $out = [];

        foreach ($students as $s) {
            $mine = $history->get($s->id, collect());
            $term = $mine->filter(fn ($r) => $r->academic_session_id === $q->session->id && $r->semester_type_id === $q->semesterType->id);
            $graded = $term->filter(fn ($r) => $r->grade !== null && $r->points !== null);
            $previous = $this->results->transcriptFrom($s, $mine->filter(fn ($r) => $order($r) < $here)->values());
            $cumulative = $this->results->transcriptFrom($s, $mine->values());
            $form = $forms->get($s->id);

            // Outstanding: failed at their latest attempt, never passed, not waived.
            $waived = $waivers->get($s->id, collect())->pluck('course_id')->flip();
            $passedEver = $mine->where('is_pass', true)->pluck('course_id')->flip();
            $latest = $mine->sortBy(fn ($r) => [...$order($r), $r->id])->groupBy('course_id')->map->last();
            $outstanding = $latest->filter(fn ($r) => $r->grade !== null && ! $r->is_pass && ! $passedEver->has($r->course_id) && ! $waived->has($r->course_id))
                ->sortBy(fn ($r) => $r->course->code)
                ->map(fn ($r) => ['code' => $r->course->code, 'units' => (int) $r->credit_units, 'reason' => 'R'])->values();
            $missing = $form ? $form->courses->reject(fn ($c) => $term->contains('course_id', $c->course_id))->count() : 0;

            $semRemark = match (true) {
                ! $form && $term->isEmpty() => 'NRI',
                $missing > 0 => 'INC',
                $outstanding->isNotEmpty() => 'REP',
                default => 'PASS',
            };
            $hasResults = $mine->isNotEmpty() || $s->legacyBalance;
            $key = $hasResults ? ResultKey::pick($keys, KeyContext::forStudent($s))
                ->first(fn ($k) => $cumulative->cgpa + 0.00001 >= $k->min_gpa && $cumulative->cgpa <= $k->max_gpa + 0.00001) : null;
            $maxYears = $this->rules->forStudent($s)['max_years'] ?? null;
            $used = $mine->map(fn ($r) => $r->academic_session_id . '-' . $r->semester_type_id)->unique()->count() + ($form && $term->isEmpty() ? 1 : 0);

            $out[$s->id] = [
                'level' => $q->level,
                'department_id' => $s->programme->department_id,
                'programme_id' => $s->programme_id,
                'study_category_id' => $s->programme->study_category_id,
                'present_tcp' => round($graded->sum(fn ($r) => $r->points * $r->credit_units), 2),
                'present_tnu' => (int) $graded->sum('credit_units'),
                'present_gpa' => GradeScale::gpa($term),
                'previous_tcp' => $previous->tcp,
                'previous_tnu' => $previous->units,
                'previous_gpa' => $previous->cgpa,
                'cumulative_tcp' => $cumulative->tcp,
                'cumulative_tnu' => $cumulative->units,
                'cgpa' => $cumulative->cgpa,
                'outstanding' => $outstanding->all(),
                'units_outstanding' => (int) $outstanding->sum('units'),
                'missing' => $missing,
                'semesters_used' => $used,
                'semesters_allowed' => $maxYears ? (int) $maxYears * $perSession : null,
                'sem_remark' => $semRemark,
                'cum_remark' => $key?->code,
                'result_key_id' => $key?->id,
                'rank' => $key?->rank ?? $cumulative->standing,
                'action' => $key?->action,
                'activity' => $key?->activity,
                'graduating' => $q->level >= max($s->programme->levels()),
            ];
        }

        return $out;
    }
}
