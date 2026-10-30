<?php

namespace App\Services\Students;

use App\Data\Tracks\TrackMove;
use App\Data\Tracks\TrackScope;
use App\Models\ComputedResult;
use App\Models\DepartmentCategory;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Models\StudentTrack;
use App\Models\TrackMigration;
use App\Models\User;
use App\Services\OperationAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Moves students on, semester by semester, as the old portal's "Migrate To Next Semester": a class (or one student)
 * goes from one session and semester to the next. After the last semester of a level they go up a level, unless their
 * computed result says to repeat it; students advised to withdraw, eligible to graduate or at the final level stay put
 * and are listed. Each run keeps the semester and cumulative remarks on the closed track and can be undone.
 */
class StudentTrackService
{
    public function __construct(private OperationAudit $audit, private \App\Services\Messenger $messenger)
    {
    }

    /** @return Collection<int, TrackMove> */
    public function plan(TrackScope $scope): Collection
    {
        if ($scope->fromSessionId === $scope->toSessionId && $scope->fromSemesterTypeId === $scope->toSemesterTypeId) {
            throw new RuntimeException('Choose a different session or semester to move to.');
        }
        $types = SemesterType::where('is_active', true)->orderBy('sort_order')->pluck('id')->values();
        $fromPos = $types->search($scope->fromSemesterTypeId);
        $results = ComputedResult::where('academic_session_id', $scope->fromSessionId)->where('semester_type_id', $scope->fromSemesterTypeId)
            ->with('resultKey')->get()->keyBy('student_id');

        return $this->students($scope)->map(function (Student $s) use ($scope, $types, $fromPos, $results) {
            $r = $results->get($s->id);
            $action = $r?->action;
            $per = DepartmentCategory::for($s->programme->department_id, $s->programme->study_category_id)?->semesters_per_level ?: max(1, $types->count());
            $lastOfLevel = $fromPos === false || ($fromPos + 1) % $per === 0;
            $level = (int) $s->current_level;
            $base = fn (?int $to, ?string $held) => new TrackMove($s->id, $s->full_name, (string) $s->matric_label, $s->programme->name, $level, $to,
                $r ? trim($r->sem_remark . ($r->cum_remark ? ' / ' . $r->cum_remark : '')) : null, $action, $r?->id, $held);

            if ($scope->needResults && ! $r) {
                return $base(null, 'No computed result for the semester yet');
            }
            if ($action === 'withdraw') {
                return $base(null, 'Advised to withdraw');
            }
            if ($action === 'graduate' || ($r?->graduating && $lastOfLevel && $action !== 'repeat')) {
                return $base(null, 'Eligible to graduate');
            }
            $to = $lastOfLevel && $action !== 'repeat' ? $level + 100 : $level;
            if (! in_array($to, $s->programme->levels(), true)) {
                return $base(null, 'Already at the final level');
            }

            return $base($to, null);
        })->values();
    }

    /** Move the students the plan moves; returns the run. */
    public function migrate(TrackScope $scope, User $by): TrackMigration
    {
        $plan = $this->plan($scope);
        if ($plan->filter->moves()->isEmpty()) {
            throw new RuntimeException('Nobody in this class can move: ' . ($plan->isEmpty() ? 'no student matches.' : 'every student is held (see the list).'));
        }

        return DB::transaction(function () use ($scope, $by, $plan) {
            $run = TrackMigration::create([
                'key' => 'MIG-' . now()->format('ymd') . '-' . Str::upper(Str::random(5)), 'from_session_id' => $scope->fromSessionId, 'from_semester_type_id' => $scope->fromSemesterTypeId,
                'to_session_id' => $scope->toSessionId, 'to_semester_type_id' => $scope->toSemesterTypeId, 'scope' => $scope->toArray(),
                'moved' => $plan->filter->moves()->count(), 'held' => $plan->reject->moves()->count(), 'created_by' => $by->id,
            ]);
            $students = Student::whereIn('id', $plan->pluck('studentId'))->get()->keyBy('id');
            foreach ($plan as $m) {
                $s = $students[$m->studentId];
                $closing = StudentTrack::where('student_id', $s->id)->where('status', 'active')->first()
                    ?? StudentTrack::create(['student_id' => $s->id, 'academic_session_id' => $scope->fromSessionId, 'semester_type_id' => $scope->fromSemesterTypeId,
                        'level' => $s->current_level, 'programme_id' => $s->programme_id, 'course_option_id' => $s->course_option_id, 'status' => 'active', 'created_by' => $by->id]);
                $closing->update(['semester_remark' => $m->remark ? explode(' / ', $m->remark)[0] : null, 'cumulative_remark' => $m->remark ? (explode(' / ', $m->remark)[1] ?? null) : null,
                    'result_action' => $m->action, 'computed_result_id' => $m->computedResultId]);
                if (! $m->moves()) {
                    continue;
                }
                $closing->update(['status' => 'closed', 'closed_by_migration_id' => $run->id]);
                StudentTrack::create([
                    'student_id' => $s->id, 'track_migration_id' => $run->id, 'academic_session_id' => $scope->toSessionId, 'semester_type_id' => $scope->toSemesterTypeId,
                    'level' => $m->toLevel, 'programme_id' => $s->programme_id, 'course_option_id' => $s->course_option_id, 'status' => 'active',
                    'previous_level' => $s->current_level, 'previous_semester_type_id' => $s->current_semester_type_id, 'created_by' => $by->id,
                ]);
                $s->update(['current_level' => $m->toLevel, 'current_semester_type_id' => $scope->toSemesterTypeId]);
                if ($s->user) {
                    $where = \App\Support\Levels::label((int) $m->toLevel, $s->programme?->studyCategory) . ', ' . (\App\Models\SemesterType::find($scope->toSemesterTypeId)?->name ?? 'next semester');
                    $this->messenger->notify($s->user, 'registration', 'You have moved on to ' . $where, "Your record now shows {$where}. Course registration for it opens when the school says so.", $s, route('my.registration'), 'success', "track{$run->id}-s{$s->id}");
                }
            }
            $op = $this->audit->begin('track_migration', 'students', $by, $scope->toSessionId, null, ['key' => $run->key]);
            $this->audit->record($op, 'moved', $run, null, ['moved' => $run->moved, 'held' => $run->held], null, null, $run->key);
            $this->audit->finish($op, $run->moved, 0, $run->held);

            return $run;
        });
    }

    /** Put everyone a run moved back where they were. Refused once a later run has moved any of them again. */
    public function undo(TrackMigration $run, User $by): void
    {
        if ($run->undone_at) {
            throw new RuntimeException('This move has already been undone.');
        }
        $tracks = $run->tracks()->get();
        if ($tracks->contains(fn ($t) => $t->status !== 'active')) {
            throw new RuntimeException('Some of these students have been moved again since. Undo that later move first.');
        }
        DB::transaction(function () use ($run, $tracks, $by) {
            foreach ($tracks as $t) {
                Student::whereKey($t->student_id)->update(['current_level' => $t->previous_level, 'current_semester_type_id' => $t->previous_semester_type_id]);
                $t->delete();
            }
            StudentTrack::where('closed_by_migration_id', $run->id)->update(['status' => 'active', 'closed_by_migration_id' => null]);
            $run->update(['undone_at' => now(), 'undone_by' => $by->id]);
            $this->audit->note('track_migration', 'students', $by, 'undone', $run, ['moved' => $run->moved], null);
        });
    }

    /** Students in the scope who are in the "from" semester and still enrolled. */
    private function students(TrackScope $scope): Collection
    {
        $q = Student::with('programme', 'user')->whereIn('status', StudentStatus::codesWhere('is_enrolled'))
            ->when($scope->programmeId, fn (Builder $q, $id) => $q->where('programme_id', $id))
            ->when($scope->departmentId, fn (Builder $q, $id) => $q->whereHas('programme', fn ($p) => $p->where('department_id', $id)))
            ->when($scope->facultyId, fn (Builder $q, $id) => $q->whereHas('programme.department', fn ($d) => $d->where('faculty_id', $id)))
            ->when($scope->studyCategoryId, fn (Builder $q, $id) => $q->whereHas('programme', fn ($p) => $p->where('study_category_id', $id)))
            ->when($scope->level, fn (Builder $q, $l) => $q->where('current_level', $l))
            ->when($scope->matric, fn (Builder $q, $m) => $q->where(fn ($w) => $w->whereRaw('UPPER(matric_number) = ?', [$m])->orWhereRaw('UPPER(jamb_reg_no) = ?', [$m])));
        $first = SemesterType::where('is_active', true)->orderBy('sort_order')->value('id');
        $q->where(fn ($w) => $w->where('current_semester_type_id', $scope->fromSemesterTypeId)
            ->when($scope->fromSemesterTypeId === $first, fn ($o) => $o->orWhereNull('current_semester_type_id')));
        // A student whose active track is already in another session or semester has been moved.
        $q->whereDoesntHave('tracks', fn ($t) => $t->where('status', 'active')->where(fn ($w) => $w->where('academic_session_id', '!=', $scope->fromSessionId)->orWhere('semester_type_id', '!=', $scope->fromSemesterTypeId)));

        return $q->orderBy('current_level')->orderBy('last_name')->get();
    }
}
