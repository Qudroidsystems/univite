<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\CourseResult;
use App\Models\ProgrammeCourse;
use App\Models\RegistrationForm;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\StudentStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Who may register, which courses they are offered, and the draft -> submitted -> approved life of a registration form. */
class RegistrationService
{
    /** The session and semester type registration is happening in, or null when no semester is current. */
    public function context(): ?array
    {
        $semester = Semester::current();
        if (! $semester) {
            return null;
        }
        $type = SemesterType::where('sort_order', $semester->sort_order)->first();

        return $type ? ['session' => $semester->session, 'type' => $type, 'semester' => $semester] : null;
    }

    /** Reasons this student cannot register right now. Empty = may register. @return string[] */
    public function blockers(Student $student, ?array $ctx = null): array
    {
        $ctx ??= $this->context();
        $why = [];

        if (! $ctx) {
            return ['No semester is running right now.'];
        }
        if (! RegistrationSettings::on('open')) {
            $why[] = 'Course registration is closed.';
        }
        if (! $ctx['type']->is_teaching) {
            $why[] = $ctx['type']->name . ' is not a teaching semester, so there is nothing to register.';
        }
        if (! (StudentStatus::byCode($student->status)?->can_register)) {
            $why[] = 'Your status (' . StudentStatus::label($student->status) . ') does not allow course registration.';
        }
        if (RegistrationSettings::on('require_clearance') && $student->needs_onboarding && ! $student->cleared_at) {
            $why[] = 'Finish your clearance first.';
        }
        if ($reason = $this->feeBlocker($student, $ctx)) {
            $why[] = $reason;
        }

        return $why;
    }

    private function feeBlocker(Student $student, array $ctx): ?string
    {
        $rule = RegistrationSettings::get('fee_rule');
        if ($rule === 'none') {
            return null;
        }
        $invoice = app(InvoiceService::class)->sessionInvoice($student, $ctx['session'], $ctx['type']->id)
            ?? app(InvoiceService::class)->sessionInvoice($student, $ctx['session']);
        if (! $invoice) {
            return null;   // nothing has been billed, so nothing is owed
        }

        $plan = $invoice->installment_plan ?: [];
        $needed = ($rule === 'first_instalment' && count($plan) >= 2) ? (float) $plan[0] : (float) $invoice->total;

        return (float) $invoice->amount_paid + 0.001 < $needed
            ? 'Pay your school fees (' . number_format($needed, 2) . ' needed, ' . number_format((float) $invoice->amount_paid, 2) . ' paid) before registering.'
            : null;
    }

    /** Course ids the student has passed (approved or published results only). @return Collection<int, int> */
    public function passed(Student $student): Collection
    {
        return CourseResult::where('student_id', $student->id)->where('is_pass', true)->whereIn('status', ['approved', 'published'])->pluck('course_id')->unique()->values();
    }

    /** Course ids the student failed and has not passed since. @return Collection<int, int> */
    public function failed(Student $student): Collection
    {
        $passed = $this->passed($student);

        return CourseResult::where('student_id', $student->id)->where('is_pass', false)->whereIn('status', ['approved', 'published'])
            ->pluck('course_id')->unique()->diff($passed)->values();
    }

    /**
     * Courses open to this student this semester: the curriculum for their level and semester, plus carry-overs.
     * Each row: course, status (core|required|elective), carryover, blocked (prerequisite reason or null).
     *
     * @return Collection<int, array{course: Course, status: string, carryover: bool, blocked: ?string}>
     */
    public function offered(Student $student, array $ctx): Collection
    {
        $order = $ctx['type']->sort_order;
        $passed = $this->passed($student);
        $failed = $this->failed($student);

        $current = ProgrammeCourse::with('course.prerequisites')->where('programme_id', $student->programme_id)
            ->where('level', $student->current_level)->where('semester_order', $order)->get();
        $carry = ProgrammeCourse::with('course.prerequisites')->where('programme_id', $student->programme_id)
            ->where('semester_order', $order)->whereIn('course_id', $failed)->get();

        $rows = collect();
        foreach ($current->merge($carry)->unique('course_id') as $pc) {
            if ($passed->contains($pc->course_id) || ! $pc->course->is_active) {
                continue;
            }
            $isCarry = $failed->contains($pc->course_id);
            $blocked = null;
            if (RegistrationSettings::on('check_prerequisites')) {
                $missing = $pc->course->prerequisites->whereNotIn('id', $passed->all());
                if ($missing->isNotEmpty()) {
                    $blocked = 'Needs ' . $missing->pluck('code')->implode(', ') . ' first.';
                }
            }
            $rows->push(['course' => $pc->course, 'status' => $pc->status, 'carryover' => $isCarry, 'blocked' => $blocked]);
        }

        return $rows->sortBy(fn ($r) => [$r['carryover'] ? 0 : 1, $r['course']->code])->values();
    }

    public function form(Student $student, array $ctx): RegistrationForm
    {
        return RegistrationForm::firstOrCreate(
            ['student_id' => $student->id, 'academic_session_id' => $ctx['session']->id, 'semester_type_id' => $ctx['type']->id],
            ['level' => $student->current_level, 'status' => 'draft']
        );
    }

    /**
     * Replace the courses on a draft form with the chosen ones.
     *
     * @param  int[]  $courseIds
     */
    public function select(RegistrationForm $form, array $courseIds, array $ctx): RegistrationForm
    {
        if (! $form->isEditable()) {
            throw new RuntimeException('This form has been submitted and can no longer be changed.');
        }
        $student = $form->student;
        if ($why = $this->blockers($student, $ctx)) {
            throw new RuntimeException($why[0]);
        }

        $offered = $this->offered($student, $ctx)->keyBy(fn ($r) => $r['course']->id);
        $chosen = collect($courseIds)->map(fn ($id) => (int) $id)->unique()->values();

        foreach ($chosen as $id) {
            $row = $offered->get($id) ?? throw new RuntimeException('One of the chosen courses is not offered to you this semester.');
            if ($row['blocked']) {
                throw new RuntimeException($row['course']->code . ': ' . $row['blocked']);
            }
        }

        DB::transaction(function () use ($form, $chosen, $offered, $student) {
            $form->courses()->delete();
            foreach ($chosen as $id) {
                $row = $offered[$id];
                CourseRegistration::create([
                    'registration_form_id' => $form->id, 'student_id' => $student->id, 'course_id' => $id,
                    'credit_units' => $row['course']->credit_units, 'is_carryover' => $row['carryover'], 'course_status' => $row['status'],
                ]);
            }
            $form->update(['total_units' => $form->courses()->sum('credit_units')]);
        });

        return $form->fresh('courses.course');
    }

    /** Problems that stop this form being submitted (unit limits, missing carry-overs/required courses). @return string[] */
    public function problems(RegistrationForm $form, array $ctx): array
    {
        $form->loadMissing('courses');
        $problems = [];
        $units = (int) $form->courses->sum('credit_units');
        $countable = RegistrationSettings::on('carryover_exempt') ? (int) $form->courses->where('is_carryover', false)->sum('credit_units') : $units;
        $min = RegistrationSettings::int('min_units');
        $max = RegistrationSettings::int('max_units');

        if ($form->courses->isEmpty()) {
            return ['Choose at least one course.'];
        }
        if ($units < $min) {
            $problems[] = "You have chosen {$units} units; the minimum is {$min}.";
        }
        if ($countable > $max) {
            $problems[] = "You have chosen {$countable} units; the maximum is {$max}" . (RegistrationSettings::on('carryover_exempt') ? ' (not counting carry-overs).' : '.');
        }

        $offered = $this->offered($form->student, $ctx);
        $chosenIds = $form->courses->pluck('course_id');
        if (RegistrationSettings::on('force_carryovers')) {
            $missing = $offered->where('carryover', true)->filter(fn ($r) => ! $chosenIds->contains($r['course']->id) && ! $r['blocked']);
            if ($missing->isNotEmpty()) {
                $problems[] = 'Carry-over courses must be registered: ' . $missing->map(fn ($r) => $r['course']->code)->implode(', ') . '.';
            }
        }
        $core = $offered->where('status', 'required')->filter(fn ($r) => ! $chosenIds->contains($r['course']->id) && ! $r['blocked']);
        if ($core->isNotEmpty()) {
            $problems[] = 'Compulsory courses missing: ' . $core->map(fn ($r) => $r['course']->code)->implode(', ') . '.';
        }

        return $problems;
    }

    public function submit(RegistrationForm $form, array $ctx): RegistrationForm
    {
        if (! $form->isEditable()) {
            throw new RuntimeException('This form has already been submitted.');
        }
        if ($why = $this->blockers($form->student, $ctx)) {
            throw new RuntimeException($why[0]);
        }
        if ($problems = $this->problems($form, $ctx)) {
            throw new RuntimeException(implode(' ', $problems));
        }

        $approved = ! RegistrationSettings::on('require_approval');
        $form->update([
            'status' => $approved ? 'approved' : 'submitted', 'submitted_at' => now(), 'note' => null,
            'decided_by' => null, 'decided_at' => $approved ? now() : null,
        ]);

        return $form;
    }

    public function decide(RegistrationForm $form, bool $approve, int $userId, ?string $note = null): RegistrationForm
    {
        if ($form->status !== 'submitted') {
            throw new RuntimeException('Only a submitted form can be approved or returned.');
        }
        if (! $approve && blank($note)) {
            throw new RuntimeException('Say why the form is being returned.');
        }
        $form->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $userId, 'decided_at' => now(), 'note' => $note]);

        return $form;
    }

    /** A student may reopen an approved form only if staff reopen it; staff do that here. */
    public function reopen(RegistrationForm $form): RegistrationForm
    {
        $form->update(['status' => 'draft', 'decided_by' => null, 'decided_at' => null]);

        return $form;
    }
}
