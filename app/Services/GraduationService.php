<?php

namespace App\Services;

use App\Data\Graduation\GraduationCheck;
use App\Models\CourseRegistration;
use App\Models\CourseResult;
use App\Models\GraduationEntry;
use App\Models\GraduationList;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Models\User;
use App\Support\AcademicRules;
use App\Support\CourseType;
use App\Support\Levels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The final-year run: check each final-year student against the graduation rules, then graduate the list. */
class GraduationService
{
    public const GRADUATED = 'graduated';   // student status code given on approval

    public function __construct(private ResultService $results, private AcademicRules $rules, private OperationAudit $audit, private DocumentMailer $mailer)
    {
    }

    public static function finalLevel(Programme $programme): int
    {
        return max(1, (int) $programme->duration_years) * 100;
    }

    /** Students of the programme in (or past) its final level who are still enrolled. */
    public function candidates(Programme $programme): Collection
    {
        return Student::where('programme_id', $programme->id)->where('current_level', '>=', self::finalLevel($programme))
            ->whereIn('status', StudentStatus::codesWhere('is_enrolled'))->whereNull('graduated_at')
            ->orderBy('last_name')->orderBy('first_name')->get();
    }

    /**
     * Check one student. Returns CGPA, units passed, class and the reasons they cannot graduate yet (empty = eligible).
     *
     */
    public function check(Student $student): GraduationCheck
    {
        $programme = $student->programme;
        $rules = $this->rules->forProgramme($programme);
        $t = $this->results->transcript($student);
        $problems = [];

        $published = CourseResult::where('student_id', $student->id)->where('status', 'published')->get();
        $latest = $published->sortBy('id')->groupBy('course_id')->map->last();
        if ($latest->isEmpty()) {
            $problems[] = 'No published results.';
        }

        // Registered courses (approved forms) still without a published result.
        $done = $published->map(fn ($r) => "{$r->course_id}-{$r->academic_session_id}-{$r->semester_type_id}")->flip();
        $pending = CourseRegistration::with('course', 'form')->where('student_id', $student->id)
            ->whereHas('form', fn ($q) => $q->where('status', 'approved'))->get()
            ->reject(fn ($c) => $done->has("{$c->course_id}-{$c->form->academic_session_id}-{$c->form->semester_type_id}"));
        if ($pending->isNotEmpty()) {
            $problems[] = 'Results not yet published for: ' . $pending->pluck('course.code')->unique()->sort()->implode(', ') . '.';
        }

        $curriculum = ProgrammeCourse::with('course')->where('programme_id', $programme->id)->get()->keyBy('course_id');
        $electives = $curriculum->where('status', CourseType::ELECTIVE)->keys()->flip();
        $compulsoryElectives = $curriculum->where('status', CourseType::COMPULSORY_ELECTIVE)->keys()->flip();
        $typeOf = fn ($courseId) => $curriculum->get($courseId)?->status ?? CourseType::COMPULSORY;
        $mustRetake = fn ($courseId) => CourseType::mustRetake($typeOf($courseId), $rules);

        // A carry-over the department has waived no longer blocks graduation (its units still do not count as earned).
        $waived = \App\Models\CarryoverWaiver::courseIdsFor($student->id)->flip();
        $failed = $latest->filter(fn ($r) => ! $r->is_pass && ! $waived->has($r->course_id) && $mustRetake($r->course_id));
        if ($failed->isNotEmpty()) {
            $problems[] = 'Failed and not yet passed: ' . $failed->map(fn ($r) => $r->course->code)->sort()->implode(', ') . '.';
        }

        if ($rules['require_core']) {
            $passed = $latest->where('is_pass', true)->keys()->flip();
            $attempted = $latest->keys()->flip();
            $missing = $curriculum->filter(fn ($pc) => $pc->status === CourseType::COMPULSORY && $pc->level >= (int) ($student->entry_level ?: 100)
                && ! $passed->has($pc->course_id) && ! $attempted->has($pc->course_id) && ! $pending->contains('course_id', $pc->course_id));
            if ($missing->isNotEmpty()) {
                $problems[] = 'Compulsory courses never taken: ' . $missing->map(fn ($pc) => $pc->course->code)->sort()->implode(', ') . '.';
            }
        }

        if ($rules['graduation_units'] && $t->earned < $rules['graduation_units']) {
            $problems[] = "Passed {$t->earned} of the {$rules['graduation_units']} units needed.";
        }
        if ($rules['min_compulsory_elective_units_total']) {
            $ceUnits = (int) $latest->filter(fn ($r) => $r->is_pass && $compulsoryElectives->has($r->course_id))->sum('credit_units');
            if ($ceUnits < $rules['min_compulsory_elective_units_total']) {
                $problems[] = "Passed {$ceUnits} of the {$rules['min_compulsory_elective_units_total']} compulsory elective units needed.";
            }
        }
        if ($rules['min_elective_units']) {
            $electiveUnits = (int) $latest->filter(fn ($r) => $r->is_pass && $electives->has($r->course_id))->sum('credit_units');
            if ($electiveUnits < $rules['min_elective_units']) {
                $problems[] = "Passed {$electiveUnits} of the {$rules['min_elective_units']} elective units needed.";
            }
        }
        if ($rules['min_graduation_cgpa'] !== null && $latest->isNotEmpty() && $t->cgpa < $rules['min_graduation_cgpa']) {
            $problems[] = 'CGPA ' . number_format($t->cgpa, 2) . ' is below the ' . number_format($rules['min_graduation_cgpa'], 2) . ' needed to graduate.';
        }
        if ($rules['max_years'] && ($years = self::yearsUsed($student)) > $rules['max_years']) {
            $problems[] = "Has spent {$years} sessions on the programme; the limit is {$rules['max_years']}. Needs approval to continue.";
        }

        return new GraduationCheck($t->cgpa, $t->earned, $t->class, $problems);
    }

    /** Sessions in which the student registered for courses. */
    public static function yearsUsed(Student $student): int
    {
        return (int) $student->registrationForms()->where('status', 'approved')->distinct()->count('academic_session_id');
    }

    /** Create or refresh the programme's list for a session. Staff exclusions are kept for students still on it. */
    public function run(Programme $programme, int $sessionId, User $user): GraduationList
    {
        $list = GraduationList::firstOrCreate(['programme_id' => $programme->id, 'academic_session_id' => $sessionId], ['created_by' => $user->id]);
        if (! $list->isDraft()) {
            throw new RuntimeException('This graduation list has been approved and can no longer be changed.');
        }

        DB::transaction(function () use ($list, $programme) {
            $students = $this->candidates($programme)->load('programme');
            $list->entries()->whereNotIn('student_id', $students->pluck('id'))->delete();
            foreach ($students as $s) {
                $c = $this->check($s);
                GraduationEntry::updateOrCreate(['graduation_list_id' => $list->id, 'student_id' => $s->id], [
                    'cgpa' => $c->cgpa, 'units_passed' => $c->unitsPassed, 'degree_class' => $c->degreeClass,
                    'is_eligible' => $c->eligible(), 'problems' => $c->problems,
                ]);
            }
            $list->update(['checked_at' => now()]);
        });

        return $list->fresh();
    }

    /** Hold a student back from this list (or put them back). */
    public function exclude(GraduationEntry $entry, bool $exclude, ?string $note): GraduationEntry
    {
        if (! $entry->list->isDraft()) {
            throw new RuntimeException('This graduation list has been approved and can no longer be changed.');
        }
        if ($exclude && blank($note)) {
            throw new RuntimeException('Give the reason for holding this student back.');
        }
        $entry->update(['is_excluded' => $exclude, 'exclusion_note' => $exclude ? $note : null]);

        return $entry;
    }

    /** Re-check everyone, then graduate the eligible students who are not held back. Returns how many graduated. */
    public function approve(GraduationList $list, User $user): int
    {
        $list = $this->run($list->programme, $list->academic_session_id, $user);
        $graduating = $list->entries()->with('student')->get()->filter->graduates();
        if ($graduating->isEmpty()) {
            throw new RuntimeException('No student on this list is eligible to graduate.');
        }

        DB::transaction(function () use ($list, $user, $graduating) {
            $audit = $this->audit;
            $op = $audit->begin('graduation_approve', 'graduation', $user, $list->academic_session_id, null, ['graduation_list_id' => $list->id, 'programme' => $list->programme?->name]);
            $status = StudentStatus::byCode(self::GRADUATED) ? self::GRADUATED : null;
            foreach ($graduating as $e) {
                $before = ['status' => $e->student->status, 'graduated_at' => $e->student->graduated_at?->toDateTimeString(), 'degree_class' => $e->student->degree_class, 'final_cgpa' => $e->student->final_cgpa];
                $e->student->update(array_filter([
                    'status' => $status, 'graduated_at' => now(), 'degree_class' => $e->degree_class, 'final_cgpa' => $e->cgpa,
                ], fn ($v) => $v !== null));
                $s = $e->student->fresh();
                $audit->record($op, 'student_graduated', $s, $before, ['status' => $s->status, 'graduated_at' => $s->graduated_at?->toDateTimeString(), 'degree_class' => $s->degree_class, 'final_cgpa' => $s->final_cgpa]);
            }
            $audit->record($op, 'list_approved', $list, ['status' => $list->status], ['status' => 'approved']);
            $list->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
            $audit->finish($op, $graduating->count());
        });
        $mailer = $this->mailer;
        $graduating->each(fn ($e) => $mailer->graduation($e->student->fresh(), true, null, $user));

        return $graduating->count();
    }

    public static function levelLabel(Programme $programme): string
    {
        return Levels::label(self::finalLevel($programme), $programme->studyCategory);
    }
}
