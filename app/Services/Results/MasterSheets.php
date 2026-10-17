<?php

namespace App\Services\Results;

use App\Data\Results\MasterSheet;
use App\Data\Results\MasterSheetCourse;
use App\Data\Results\MasterSheetRow;
use App\Models\AcademicSession;
use App\Models\CarryoverWaiver;
use App\Models\CourseRegistration;
use App\Models\CourseResult;
use App\Models\Programme;
use App\Models\ResultSheet;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use App\Services\ResultService;
use App\Support\AcademicRules;
use App\Support\CourseType;
use App\Support\GradeScale;
use App\Support\Institution;
use App\Support\Xlsx;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The master sheet of a programme, level and semester: every registered student against every course they took,
 * with semester and cumulative totals and the remark. Senate (and the heads and deans before it) read it while the
 * score sheets are still going through approval, so by default it includes results that are submitted or approved
 * but not yet published; students only ever see published results. From here a whole level can be approved at the
 * person's step, or published, in one go.
 */
class MasterSheets
{
    /** Result statuses the provisional sheet counts (drafts are still the lecturer's). */
    private const PROVISIONAL = ['submitted', 'approved', 'published'];

    public function __construct(private ResultService $results, private ScopedAccess $access, private AcademicRules $rules)
    {
    }

    /** Whether this person may open master sheets of this programme. */
    public function canView(User $user, Programme $programme): bool
    {
        if ($user->canAny(['Approve results for any stage', 'Publish results'])) {
            return true;
        }

        return $this->access->scope($user, 'View student results')->coversDepartment($programme->department_id)
            || $this->access->scope($user, 'Approve results')->coversDepartment($programme->department_id);
    }

    /** @return Collection<int, Programme> the programmes this person may open */
    public function programmesFor(User $user): Collection
    {
        return Programme::orderBy('name')->get()->filter(fn ($p) => $this->canView($user, $p))->values();
    }

    public function build(Programme $programme, int $level, AcademicSession $session, SemesterType $type, bool $provisional = true): MasterSheet
    {
        $statuses = $provisional ? self::PROVISIONAL : ['published'];
        $students = Student::with('legacyBalance')->where('programme_id', $programme->id)
            ->whereHas('registrationForms', fn ($q) => $q->where('level', $level)->where('academic_session_id', $session->id)->where('semester_type_id', $type->id)->where('status', 'approved'))
            ->get()->sortBy(fn ($s) => [$s->matric_number ?? 'zzz', $s->last_name, $s->first_name])->values();
        $ids = $students->pluck('id');

        $registered = CourseRegistration::with('course.department')->whereIn('student_id', $ids)
            ->whereHas('form', fn ($q) => $q->where('academic_session_id', $session->id)->where('semester_type_id', $type->id)->where('status', 'approved'))
            ->get();
        $courses = $registered->pluck('course')->unique('id')->sortBy('code')->values();
        $byStudent = $registered->groupBy('student_id');

        // Every counted result of these students up to and including this semester (for the cumulative columns).
        $order = fn ($r) => [$r->session->name, $r->semesterType->sort_order];
        $here = [$session->name, $type->sort_order];
        $history = CourseResult::with('course', 'session', 'semesterType')->whereIn('student_id', $ids)->whereIn('status', $statuses)->get()
            ->filter(fn ($r) => $order($r) <= $here)->groupBy('student_id');
        $waivers = CarryoverWaiver::active()->with('course')->whereIn('student_id', $ids)->get()->groupBy('student_id');
        $types = \App\Models\ProgrammeCourse::where('programme_id', $programme->id)->pluck('status', 'course_id');

        $rows = $students->map(function (Student $s) use ($history, $session, $type, $byStudent, $waivers, $order, $here, $types) {
            $mine = $history->get($s->id, collect());
            $term = $mine->filter(fn ($r) => $r->academic_session_id === $session->id && $r->semester_type_id === $type->id)->keyBy('course_id');
            $before = $mine->filter(fn ($r) => $order($r) < $here)->values();
            $previous = $this->results->transcriptFrom($s, $before);
            $cumulative = $this->results->transcriptFrom($s, $mine->values());

            $graded = $term->filter(fn ($r) => $r->grade !== null && $r->points !== null);
            $waived = $waivers->get($s->id, collect())->keyBy('course_id');
            $latest = $mine->sortBy(fn ($r) => [...$order($r), $r->id])->groupBy('course_id')->map->last();
            $passedEver = $mine->where('is_pass', true)->pluck('course_id')->flip();
            $rules = $this->rules->forStudent($s);
            $failed = $latest->filter(fn ($r) => ! $r->is_pass && $r->grade !== null && ! $passedEver->has($r->course_id)
                && CourseType::mustRetake($types->get($r->course_id), $rules));
            $carryovers = $failed->reject(fn ($r) => $waived->has($r->course_id))->map(fn ($r) => $r->course->code)->sort()->values();
            $outstanding = $byStudent->get($s->id, collect())->reject(fn ($c) => $term->has($c->course_id))->map(fn ($c) => $c->course->code)->sort()->values();
            $missing = $outstanding->count();

            $bucket = match (true) {
                $missing > 0 => 'incomplete',
                $cumulative->standing === 'Advised to withdraw' => 'withdraw',
                $cumulative->standing === 'On probation' => 'probation',
                $carryovers->isNotEmpty() => 'carryover',
                default => 'pass',
            };
            $remark = match ($bucket) {
                'incomplete' => "Incomplete ({$missing} " . ($missing === 1 ? 'result' : 'results') . ' outstanding)',
                'withdraw' => 'Advised to withdraw',
                'probation' => 'Probation' . ($carryovers->isNotEmpty() ? '; carry over ' . $carryovers->implode(', ') : ''),
                'carryover' => 'Carry over ' . $carryovers->implode(', '),
                default => 'Pass',
            };

            return new MasterSheetRow(
                student: $s,
                results: $term,
                tcp: round($graded->sum(fn ($r) => $r->points * $r->credit_units), 2),
                tnu: (int) $graded->sum('credit_units'),
                gpa: GradeScale::gpa($term),
                previousUnits: $previous->units,
                previousPoints: $previous->tcp,
                previousCgpa: $previous->cgpa,
                cumulativeUnits: $cumulative->units,
                cumulativePoints: $cumulative->tcp,
                cgpa: $cumulative->cgpa,
                carryovers: $carryovers,
                waived: $waived->map(fn ($w) => $w->course->code)->sort()->values(),
                standing: $cumulative->standing,
                missing: $missing,
                outstanding: $outstanding,
                remark: $remark,
                bucket: $bucket,
                registered: $byStudent->get($s->id, collect())->pluck('course_id')->values(),
            );
        });

        $sheets = ResultSheet::with('stage')->whereIn('course_id', $courses->pluck('id'))
            ->where('academic_session_id', $session->id)->where('semester_type_id', $type->id)->get()->keyBy('course_id');
        $takers = $registered->groupBy('course_id')->map->count();
        $columns = $courses->map(function ($course) use ($rows, $sheets, $takers, $types) {
            $marks = $rows->map(fn (MasterSheetRow $r) => $r->results->get($course->id))->filter();

            return new MasterSheetCourse(
                course: $course,
                sheet: $sheets->get($course->id),
                registered: (int) $takers->get($course->id, 0),
                passed: $marks->where('is_pass', true)->count(),
                failed: $marks->where('is_pass', false)->count(),
                average: $marks->isEmpty() ? null : round((float) $marks->avg('total'), 1),
                type: $types->get($course->id) ?? $course->course_type,
            );
        });

        return new MasterSheet($programme, $level, $session, $type, $columns, $rows, $provisional);
    }

    // ---- Approving a whole level ---------------------------------------------------------------------

    /** @return Collection<int, ResultSheet> sheets on this master sheet waiting at a step this person signs */
    public function approvable(MasterSheet $sheet, User $user): Collection
    {
        return $sheet->courses->map(fn (MasterSheetCourse $c) => $c->sheet)->filter()
            ->filter(fn (ResultSheet $s) => $this->results->canApprove($user, $s))->values();
    }

    /** @return Collection<int, ResultSheet> approved sheets this person may publish */
    public function publishable(MasterSheet $sheet, User $user): Collection
    {
        if (! $user->can('Publish results')) {
            return collect();
        }

        return $sheet->courses->map(fn (MasterSheetCourse $c) => $c->sheet)->filter()->where('status', 'approved')->values();
    }

    /**
     * Course codes on this master sheet that students outside this programme and level also take (general studies,
     * service courses). Approving or publishing their sheet does so for every student on it.
     *
     * @return Collection<int, string>
     */
    public function sharedCourses(MasterSheet $sheet): Collection
    {
        $here = $sheet->rows->map(fn (MasterSheetRow $r) => $r->student->id)->all();

        return $sheet->courses->filter(fn (MasterSheetCourse $c) => $c->sheet && $c->sheet->results()->whereNotIn('student_id', $here)->exists())
            ->map(fn (MasterSheetCourse $c) => $c->course->code)->values();
    }

    /** Approve, at this person's step, every sheet on the master sheet that waits for them. @return int sheets approved */
    public function approveAll(MasterSheet $sheet, User $user, ?string $note = null): int
    {
        $sheets = $this->approvable($sheet, $user);
        if ($sheets->isEmpty()) {
            throw new RuntimeException('No score sheet on this master sheet is waiting for your approval.');
        }
        $note = trim(($note ? $note . ' ' : '') . '(approved with the master sheet of ' . $sheet->title() . ')');
        foreach ($sheets as $s) {
            $this->results->approve($s, $user, $note);
        }

        return $sheets->count();
    }

    /** Publish every approved sheet on the master sheet. @return int sheets published */
    public function publishAll(MasterSheet $sheet, User $user): int
    {
        $sheets = $this->publishable($sheet, $user);
        if ($sheets->isEmpty()) {
            throw new RuntimeException('No score sheet on this master sheet is approved and waiting to be published.');
        }
        foreach ($sheets as $s) {
            $this->results->publish($s, $user);
        }

        return $sheets->count();
    }

    // ---- Excel ---------------------------------------------------------------------------------------

    /** The master sheet as an Excel file (its contents). */
    public function excel(MasterSheet $sheet): string
    {
        $head = ['S/N', 'Matric number', 'Name'];
        foreach ($sheet->courses as $c) {
            $head[] = "{$c->course->code} ({$c->course->credit_units}) " . CourseType::short($c->type);
        }
        array_push($head, 'Present TCP', 'Present TNU', 'GPA', 'Previous TCP', 'Previous TNU', 'Previous CGPA', 'Cumulative TCP', 'Cumulative TNU', 'CGPA', 'Outstanding results', 'Remark');

        $table = [$head];
        foreach ($sheet->rows->values() as $i => $r) {
            $line = [$i + 1, $r->student->matric_number ?? '', $r->student->full_name];
            foreach ($sheet->courses as $c) {
                $x = $r->results->get($c->course->id);
                $line[] = $x ? self::score($x->total) . ' ' . $x->grade : $r->mark($c->course->id);
            }
            array_push($line, $r->tcp, $r->tnu, round($r->gpa, 2), $r->previousPoints, $r->previousUnits, round($r->previousCgpa, 2),
                $r->cumulativePoints, $r->cumulativeUnits, round($r->cgpa, 2), $r->outstanding->implode(', '), $r->remark . ($r->waived->isNotEmpty() ? ' (waived: ' . $r->waived->implode(', ') . ')' : ''));
            $table[] = $line;
        }

        $notes = [
            $sheet->title(),
            $sheet->provisional ? 'Includes results still going through approval; students only see published results.' : 'Published results only.',
            'C: compulsory, CE: compulsory elective, E: elective. NR: not registered. AR: awaiting result (registered, no score yet).',
            'TCP: total credit points (grade point x units). TNU: total number of units. GPA = TCP / TNU. Previous: before this semester; cumulative: including it.',
            '',
            'Where each course is:',
        ];
        foreach ($sheet->courses as $c) {
            $notes[] = "{$c->course->code} {$c->course->title} (" . CourseType::label($c->type) . ', ' . ($c->course->department?->name ?? 'no department') . "): {$c->statusLabel()}. {$c->passed} passed, {$c->failed} failed" . ($c->average !== null ? ", average {$c->average}" : '') . '.';
        }
        $notes[] = '';
        foreach (MasterSheet::BUCKETS as $k => $label) {
            $notes[] = "{$label}: " . ($sheet->counts()[$k] ?? 0);
        }

        $widths = [6, 18, 30];
        foreach ($sheet->courses as $c) {
            $widths[] = 11;
        }
        array_push($widths, 9, 9, 6, 9, 9, 9, 11, 11, 7, 30, 40);

        return Xlsx::write($table, 'Master sheet', ['widths' => $widths, 'notes' => $notes, 'notes_title' => 'About this master sheet', 'notes_sheet' => 'About',
            'letterhead' => Institution::letterhead([$sheet->title(), $sheet->provisional ? 'Provisional: includes results still going through approval' : 'Published results']),
            'freeze' => 3, 'color' => Institution::get('primary_color')]);
    }

    public static function score($total): string
    {
        return rtrim(rtrim(number_format((float) $total, 2), '0'), '.');
    }
}
