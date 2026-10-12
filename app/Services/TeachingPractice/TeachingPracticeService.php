<?php

namespace App\Services\TeachingPractice;

use App\Data\Results\Score;
use App\Data\TeachingPractice\ExerciseInput;
use App\Data\TeachingPractice\PostingRun;
use App\Data\TeachingPractice\ScorePublication;
use App\Data\TeachingPractice\VisitScore;
use App\Models\Student;
use App\Models\TpAssessment;
use App\Models\TpExercise;
use App\Models\TpPosting;
use App\Models\TpSchool;
use App\Models\TpSupervisor;
use App\Models\User;
use App\Services\Campus\AudienceResolver;
use App\Services\Messenger;
use App\Services\OperationAudit;
use App\Services\ResultService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Teaching practice: exercises, partner schools and their capacity, supervisors, posting students (by their choices
 * or by hand), supervisors' lesson assessments, and publishing the final score into the course's result sheet.
 */
class TeachingPracticeService
{
    public function __construct(
        private AudienceResolver $audiences,
        private ResultService $results,
        private Messenger $messenger,
        private OperationAudit $audit,
    ) {
    }

    public function save(?TpExercise $exercise, ExerciseInput $in, User $by): TpExercise
    {
        if (! $in->criteria) {
            throw new RuntimeException('Add at least one marking criterion.');
        }
        if ($exercise && $exercise->postings()->whereHas('assessments')->exists() && $exercise->criteria != $in->criteria) {
            throw new RuntimeException('Lessons have already been marked with this guide, so the criteria can no longer be changed.');
        }
        $exercise ??= new TpExercise(['created_by' => $by->id]);
        $exercise->fill($in->attributes())->save();

        return $exercise;
    }

    /** @param  array<int, int|null>  $capacities  school id => places (empty/0 removes the school) */
    public function setSchools(TpExercise $exercise, array $capacities): void
    {
        $sync = [];
        foreach ($capacities as $schoolId => $cap) {
            if ((int) $cap > 0) {
                $sync[(int) $schoolId] = ['capacity' => (int) $cap];
            }
        }
        $removed = $exercise->schools()->pluck('tp_schools.id')->diff(array_keys($sync));
        if ($removed->isNotEmpty() && $exercise->postings()->where('status', 'posted')->whereIn('tp_school_id', $removed)->exists()) {
            throw new RuntimeException('Students are posted to a school you removed. Move them first.');
        }
        $exercise->schools()->sync($sync);
    }

    public function addSupervisor(TpExercise $exercise, TpSchool $school, User $user): TpSupervisor
    {
        if (! $user->staffProfile) {
            throw new RuntimeException($user->name . ' is not a member of staff.');
        }
        if (! $exercise->schools()->whereKey($school->id)->exists()) {
            throw new RuntimeException($school->name . ' is not one of this exercise\'s schools.');
        }
        $sup = TpSupervisor::firstOrCreate(['tp_exercise_id' => $exercise->id, 'tp_school_id' => $school->id, 'user_id' => $user->id]);
        if ($sup->wasRecentlyCreated) {
            $n = $exercise->postings()->where('tp_school_id', $school->id)->where('status', 'posted')->count();
            $this->messenger->notify($user, 'teaching_practice', 'Teaching practice supervision', 'You will supervise ' . $n . ' student(s) at ' . $school->name . ' for ' . $exercise->name . '.', $sup, route('tp.supervise'), 'info', null);
        }

        return $sup;
    }

    /** Add every student the exercise is for (who is not on it yet). */
    public function enrol(TpExercise $exercise): int
    {
        $have = $exercise->postings()->pluck('student_id')->all();
        $ids = $this->audiences->students($exercise->audienceData())->whereNotIn('students.id', $have)->pluck('students.id');
        foreach ($ids as $id) {
            TpPosting::create(['tp_exercise_id' => $exercise->id, 'student_id' => $id, 'status' => 'pending']);
        }

        return $ids->count();
    }

    /** @param  int[]  $choices */
    public function choose(TpPosting $posting, array $choices, ?string $subject): void
    {
        $ex = $posting->exercise;
        if (! $ex->choices_open || $posting->status !== 'pending') {
            throw new RuntimeException($posting->status === 'posted' ? 'You have already been posted.' : 'Choices are closed.');
        }
        $allowed = $ex->schools()->pluck('tp_schools.id')->all();
        $choices = array_slice(array_values(array_unique(array_filter(array_map('intval', $choices), fn ($id) => in_array($id, $allowed, true)))), 0, max(1, $ex->max_choices));
        $posting->update(['choices' => $choices, 'subject' => $subject]);
    }

    /** @return array<int, int> school id => places left */
    public function placesLeft(TpExercise $exercise): array
    {
        $used = $exercise->postings()->where('status', 'posted')->selectRaw('tp_school_id, count(*) as n')->groupBy('tp_school_id')->pluck('n', 'tp_school_id');

        return $exercise->schools()->get()->mapWithKeys(fn ($s) => [$s->id => max(0, (int) $s->pivot->capacity - (int) ($used[$s->id] ?? 0))])->all();
    }

    /** Post everyone not yet posted: their choices first, then the school with the most places left. */
    public function autoPost(TpExercise $exercise, User $by): PostingRun
    {
        $left = $this->placesLeft($exercise);
        $posted = $unplaced = 0;
        $op = $this->audit->begin('tp_posting', 'teaching_practice', $by, $exercise->academic_session_id, null, ['exercise' => $exercise->name]);
        $exercise->postings()->with('student.user')->where('status', 'pending')->orderByRaw('case when choices is null then 1 else 0 end')->orderBy('id')->get()
            ->each(function (TpPosting $p) use (&$left, &$posted, &$unplaced, $by, $op) {
                $school = collect($p->choices ?? [])->first(fn ($id) => ($left[$id] ?? 0) > 0);
                if (! $school && $left) {
                    arsort($left);
                    $school = array_key_first($left);
                    $school = $left[$school] > 0 ? $school : null;
                }
                if (! $school) {
                    $unplaced++;

                    return;
                }
                $left[$school]--;
                $this->place($p, TpSchool::find($school), $by);
                $this->audit->record($op, 'posted', $p, null, ['student' => $p->student->matric_number, 'school' => $p->school->name]);
                $posted++;
            });
        $this->audit->finish($op, $posted, $unplaced, 0);

        return new PostingRun($posted, $unplaced);
    }

    /** Post (or move) one student to a school. */
    public function post(TpPosting $posting, TpSchool $school, User $by, bool $overCapacity = false): void
    {
        $left = $this->placesLeft($posting->exercise);
        if (! array_key_exists($school->id, $left)) {
            throw new RuntimeException($school->name . ' is not one of this exercise\'s schools.');
        }
        if ($posting->tp_school_id !== $school->id && $left[$school->id] <= 0 && ! $overCapacity) {
            throw new RuntimeException($school->name . ' has no places left.');
        }
        if ($posting->assessments()->exists() && $posting->tp_school_id !== $school->id) {
            throw new RuntimeException('This student has already been assessed at ' . $posting->school->name . '.');
        }
        $this->place($posting, $school, $by);
    }

    private function place(TpPosting $p, TpSchool $school, User $by): void
    {
        $p->update(['tp_school_id' => $school->id, 'status' => 'posted', 'posted_at' => now(), 'posted_by' => $by->id]);
        $p->setRelation('school', $school);
        $this->messenger->notify($p->student->user, 'teaching_practice', 'Teaching practice posting', 'You have been posted to ' . $school->name . ($school->place() ? ', ' . $school->place() : '') . ' for ' . $p->exercise->name . '. Print your posting letter from the portal.',
            $p, route('my.tp'), 'success', null);
    }

    public function withdraw(TpPosting $posting): void
    {
        if ($posting->assessments()->exists()) {
            throw new RuntimeException('This student has assessments recorded and cannot be withdrawn.');
        }
        $posting->update(['status' => 'withdrawn', 'tp_school_id' => null]);
    }

    public function supervises(User $user, TpPosting $posting): bool
    {
        return $posting->tp_school_id !== null && TpSupervisor::where('tp_exercise_id', $posting->tp_exercise_id)->where('tp_school_id', $posting->tp_school_id)->where('user_id', $user->id)->exists();
    }

    public function assess(TpPosting $posting, VisitScore $v, User $by): TpAssessment
    {
        $ex = $posting->exercise;
        if ($posting->status !== 'posted') {
            throw new RuntimeException('This student has not been posted.');
        }
        if ($v->visitNo > $ex->visits_required) {
            throw new RuntimeException('This exercise has ' . $ex->visits_required . ' assessed visit(s).');
        }
        $total = 0.0;
        $marks = [];
        foreach ($ex->criteria as $c) {
            $m = $v->marks[$c['key']] ?? null;
            if ($m === null) {
                throw new RuntimeException('Give a mark for "' . $c['label'] . '".');
            }
            if ($m > $c['max']) {
                throw new RuntimeException('"' . $c['label'] . '" is out of ' . $c['max'] . '.');
            }
            $marks[$c['key']] = $m;
            $total += $m;
        }

        return TpAssessment::updateOrCreate(['tp_posting_id' => $posting->id, 'visit_no' => $v->visitNo], [
            'supervisor_id' => $by->id, 'visited_on' => $v->visitedOn, 'lesson_topic' => $v->lessonTopic, 'scores' => $marks,
            'percent' => round($total / max(1, $ex->maxMarks()) * 100, 2), 'remarks' => $v->remarks,
        ]);
    }

    /** Average of the assessed visits as a percentage, or null until every required visit is marked. */
    public function finalScore(TpPosting $posting): ?float
    {
        $visits = $posting->relationLoaded('assessments') ? $posting->assessments : $posting->assessments()->get();
        if ($visits->count() < $posting->exercise->visits_required) {
            return null;
        }

        return round((float) $visits->avg('percent'), 2);
    }

    /**
     * Write each student's score into the course's result sheet, split between CA and exam in the sheet's proportions
     * (e.g. 72% becomes 21.6/30 + 50.4/70). The sheet then goes through the usual approval steps. Can be run again
     * (for students marked later) until the sheet is submitted.
     */
    public function publish(TpExercise $exercise, User $by): ScorePublication
    {
        if (! $exercise->course_id || ! $exercise->semester_type_id) {
            throw new RuntimeException('Choose the course and semester this exercise is graded under first.');
        }
        $sheet = $this->results->sheet($exercise->course_id, $exercise->academic_session_id, $exercise->semester_type_id);
        if (! $sheet->isEditable()) {
            throw new RuntimeException('The result sheet for this course has already been submitted.');
        }
        $onSheet = $sheet->results()->pluck('student_id')->all();
        $caMax = ResultService::caMax();
        $examMax = ResultService::examMax();
        $scores = $incomplete = $missing = [];
        $exercise->postings()->with('assessments', 'student', 'exercise')->where('status', 'posted')->get()->each(function (TpPosting $p) use (&$scores, &$incomplete, &$missing, $onSheet, $caMax, $examMax) {
            $pct = $this->finalScore($p);
            if ($pct === null) {
                $incomplete[] = $p->student->full_name . ' (' . $p->student->matric_number . ')';

                return;
            }
            if (! in_array($p->student_id, $onSheet, true)) {
                $missing[] = $p->student->full_name . ' (' . $p->student->matric_number . ')';

                return;
            }
            $ca = round($pct / 100 * $caMax, 2);
            $scores[$p->student_id] = new Score($ca, round($pct / 100 * ($caMax + $examMax) - $ca, 2));
            $p->update(['final_score' => $pct]);
        });
        $problems = DB::transaction(fn () => $this->results->saveScores($sheet, $scores, $by, 'teaching practice scores'));
        $exercise->update(['scores_published_at' => now()]);
        $this->audit->note('tp_scores', 'teaching_practice', $by, 'scores_published', $exercise, null, ['saved' => count($scores), 'incomplete' => count($incomplete)]);

        return new ScorePublication(count($scores) - count($problems), $incomplete, $missing, $problems);
    }

    public function postingFor(Student $student, TpExercise $exercise): ?TpPosting
    {
        return TpPosting::where('tp_exercise_id', $exercise->id)->where('student_id', $student->id)->first();
    }
}
