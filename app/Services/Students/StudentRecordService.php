<?php

namespace App\Services\Students;

use App\Data\Students\RefereeData;
use App\Data\Students\TransferRequest;
use App\Models\AcademicSession;
use App\Models\AdmissionDeferral;
use App\Models\CourseOption;
use App\Models\Programme;
use App\Models\Student;
use App\Models\StudentReferee;
use App\Models\StudentTransfer;
use App\Models\User;
use App\Services\OperationAudit;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Changes to a student's record the old portal had as separate screens: transfers between programmes, deferred
 * admissions and their resumption, and referees. Each change is kept on the operation history.
 */
class StudentRecordService
{
    public function __construct(private OperationAudit $audit, private \App\Services\Messenger $messenger)
    {
    }

    public function transfer(Student $student, TransferRequest $r, User $by): StudentTransfer
    {
        $to = Programme::findOrFail($r->programmeId);
        if (! in_array($r->level, $to->levels(), true)) {
            throw new RuntimeException("{$to->name} runs levels " . implode(', ', $to->levels()) . '.');
        }
        if ($r->courseOptionId && ! CourseOption::where('id', $r->courseOptionId)->where('programme_id', $to->id)->exists()) {
            throw new RuntimeException('That course option belongs to another programme.');
        }
        if ($to->id === $student->programme_id && $r->level === (int) $student->current_level && $r->courseOptionId === $student->course_option_id) {
            throw new RuntimeException('The student is already in that programme, option and level.');
        }

        return DB::transaction(function () use ($student, $r, $by, $to) {
            $before = ['programme' => $student->programme?->name, 'level' => $student->current_level, 'course_option_id' => $student->course_option_id];
            $t = StudentTransfer::create([
                'student_id' => $student->id, 'from_programme_id' => $student->programme_id, 'to_programme_id' => $to->id,
                'from_course_option_id' => $student->course_option_id, 'to_course_option_id' => $r->courseOptionId,
                'from_level' => $student->current_level, 'to_level' => $r->level, 'academic_session_id' => AcademicSession::current()?->id,
                'reason' => $r->reason, 'transferred_by' => $by->id,
            ]);
            $student->update(['programme_id' => $to->id, 'course_option_id' => $r->courseOptionId, 'current_level' => $r->level,
                'sub_department_id' => $to->sub_department_id ?? null]);
            $this->audit->note('student_transfer', 'students', $by, 'transferred', $student, $before, ['programme' => $to->name, 'level' => $r->level, 'course_option_id' => $r->courseOptionId], $r->reason);
            if ($student->user) {
                $this->messenger->notify($student->user, 'registration', 'You have been transferred to ' . $to->name, 'Your programme is now ' . $to->name . ' at ' . \App\Support\Levels::label($r->level, $to->studyCategory) . '. Your courses and timetable follow the new programme.', $student, route('my.registration'), 'info');
            }

            return $t;
        });
    }

    /** Put off a student's studies to a later session; they cannot register until it is resumed. */
    public function defer(Student $student, int $toSessionId, string $reason, User $by): AdmissionDeferral
    {
        if ($student->deferrals()->where('status', 'deferred')->exists()) {
            throw new RuntimeException('This student is already deferred.');
        }
        $from = AcademicSession::current() ?? $student->entrySession;
        $to = AcademicSession::findOrFail($toSessionId);
        if ($from && strcmp($to->name, $from->name) <= 0) {
            throw new RuntimeException('Choose a later session than ' . $from->name . '.');
        }

        return DB::transaction(function () use ($student, $from, $to, $reason, $by) {
            $d = AdmissionDeferral::create([
                'student_id' => $student->id, 'from_session_id' => $from?->id ?? $student->academic_session_id, 'to_session_id' => $to->id,
                'reason' => $reason, 'previous_status' => $student->status, 'status' => 'deferred', 'deferred_by' => $by->id,
            ]);
            $student->update(['status' => 'deferred']);
            $this->audit->note('admission_deferral', 'students', $by, 'deferred', $student, ['status' => $d->previous_status], ['status' => 'deferred', 'until' => $to->name], $reason);
            if ($student->user) {
                $this->messenger->notify($student->user, 'registration', 'Your studies are deferred to ' . $to->name, "You cannot register courses until {$to->name}. Reason given: {$reason}", $student, route('dashboard'), 'warning');
            }

            return $d;
        });
    }

    /**
     * Bring a deferred student back. A newly admitted student who never started takes the new session as their entry
     * session, so their class, fees and matric year follow it; others simply carry on.
     */
    public function resume(AdmissionDeferral $d, User $by): void
    {
        if ($d->status !== 'deferred') {
            throw new RuntimeException('This deferral has already been resumed or cancelled.');
        }
        DB::transaction(function () use ($d, $by) {
            $student = $d->student;
            $neverStarted = ! $student->matric_number && ! $student->cleared_at;
            $student->update(array_filter([
                'status' => $d->previous_status && $d->previous_status !== 'deferred' ? $d->previous_status : \App\Models\StudentStatus::defaultCode(),
                'academic_session_id' => $neverStarted ? $d->to_session_id : null,
            ]));
            $d->update(['status' => 'resumed', 'resumed_at' => now(), 'resumed_by' => $by->id]);
            $this->audit->note('admission_deferral', 'students', $by, 'resumed', $student, ['status' => 'deferred'], ['status' => $student->status]);
            if ($student->user) {
                $this->messenger->notify($student->user, 'registration', 'Welcome back: your studies have resumed', 'You can register your courses again.', $student, route('my.registration'), 'success');
            }
        });
    }

    /** @param RefereeData[] $referees */
    public function saveReferees(Student $student, array $referees): void
    {
        foreach ($referees as $r) {
            if ($r->name === null) {
                StudentReferee::where('student_id', $student->id)->where('position', $r->position)->delete();
                continue;
            }
            StudentReferee::updateOrCreate(['student_id' => $student->id, 'position' => $r->position],
                ['name' => $r->name, 'phone' => $r->phone, 'email' => $r->email, 'address' => $r->address, 'occupation' => $r->occupation]);
        }
    }
}
