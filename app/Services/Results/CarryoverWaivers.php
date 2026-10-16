<?php

namespace App\Services\Results;

use App\Data\Results\CarryoverWaiverRequest;
use App\Models\CarryoverWaiver;
use App\Models\Course;
use App\Models\CourseResult;
use App\Models\Student;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use App\Services\Messenger;
use App\Services\OperationAudit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Waiving carry-overs. A waived course stays on the student's transcript and in their CGPA; it just stops being a
 * carry-over: it is no longer put on (or required on) their registration form, it is not listed as outstanding on the
 * result summary or master sheet, and it does not stop them graduating. The head of the student's department (or
 * anyone with "Waive carryover courses" for it) grants it with a reason; it can be withdrawn the same way.
 */
class CarryoverWaivers
{
    public function __construct(private ScopedAccess $access, private OperationAudit $audit, private Messenger $messenger)
    {
    }

    public function canManage(User $user, Student $student): bool
    {
        $student->loadMissing('programme');

        return $this->access->scope($user, 'Waive carryover courses')->coversStudent($student);
    }

    /**
     * Courses the student failed and has not passed since, before any waiver (approved or published results).
     *
     * @return Collection<int, int>
     */
    public function failedCourseIds(Student $student): Collection
    {
        $rows = CourseResult::where('student_id', $student->id)->whereIn('status', ['approved', 'published'])->get(['course_id', 'is_pass']);
        $passed = $rows->where('is_pass', true)->pluck('course_id')->unique();

        return $rows->where('is_pass', false)->pluck('course_id')->unique()->diff($passed)->map(fn ($id) => (int) $id)->values();
    }

    public function grant(CarryoverWaiverRequest $data, User $by): CarryoverWaiver
    {
        $student = Student::with('programme', 'user')->findOrFail($data->studentId);
        $course = Course::findOrFail($data->courseId);
        if (! $this->canManage($by, $student)) {
            throw new RuntimeException('Only the student\'s department can waive their carry-overs.');
        }
        if (! $this->failedCourseIds($student)->contains($course->id)) {
            throw new RuntimeException("{$course->code} is not a carry-over for {$student->full_name}.");
        }
        if (CarryoverWaiver::active()->where('student_id', $student->id)->where('course_id', $course->id)->exists()) {
            throw new RuntimeException("{$course->code} is already waived for {$student->full_name}.");
        }

        $waiver = DB::transaction(function () use ($student, $course, $data, $by) {
            $waiver = CarryoverWaiver::create(['student_id' => $student->id, 'course_id' => $course->id, 'reason' => $data->reason, 'granted_by' => $by->id]);
            $this->audit->note('carryover_waiver', 'results', $by, 'carryover_waived', $waiver, null,
                ['student' => $student->full_name, 'matric' => $student->matric_number, 'course' => $course->code], $data->reason);

            return $waiver;
        });

        if ($student->user) {
            $this->messenger->notify($student->user, 'results', 'Carry-over waived',
                "Your department has waived {$course->code} ({$course->title}). You no longer need to register it again. The result stays on your transcript.",
                $waiver, route('my.results'), 'info', 'waiver:' . $waiver->id);
        }

        return $waiver;
    }

    public function revoke(CarryoverWaiver $waiver, string $reason, User $by): CarryoverWaiver
    {
        $waiver->loadMissing('student.programme', 'student.user', 'course');
        if (! $this->canManage($by, $waiver->student)) {
            throw new RuntimeException('Only the student\'s department can withdraw this waiver.');
        }
        if (! $waiver->isActive()) {
            throw new RuntimeException('This waiver has already been withdrawn.');
        }
        if (blank($reason)) {
            throw new RuntimeException('Say why the waiver is being withdrawn.');
        }

        DB::transaction(function () use ($waiver, $reason, $by) {
            $waiver->update(['revoked_at' => now(), 'revoked_by' => $by->id, 'revoke_reason' => $reason]);
            $this->audit->note('carryover_waiver', 'results', $by, 'carryover_waiver_withdrawn', $waiver, ['active' => true],
                ['student' => $waiver->student->full_name, 'course' => $waiver->course->code, 'active' => false], $reason);
        });

        if ($waiver->student->user) {
            $this->messenger->notify($waiver->student->user, 'results', 'Carry-over waiver withdrawn',
                "Your department has withdrawn the waiver on {$waiver->course->code}. It is a carry-over again and must be registered.",
                $waiver, route('my.results'), 'warning', 'waiver-off:' . $waiver->id);
        }

        return $waiver;
    }
}
