<?php

namespace App\Services\Study;

use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\CourseResult;
use App\Models\GradeBand;
use App\Models\ResultSheet;
use App\Models\Student;
use App\Support\AcademicRules;
use App\Services\ResultService;
use App\Services\Schedule\MySchedule;
use App\Support\GradeScale;
use App\Support\MobileAppSettings;
use Illuminate\Support\Collection;

/**
 * "What grades do I need?" A student names a target GPA for this semester, or a target CGPA, and the planner works out
 * grade combinations over the courses they take this semester that reach it, using the university's own grading scale,
 * credit units and CGPA rule (a retaken course replaces the earlier attempt when the CGPA counts the latest attempt only).
 *
 * It never promises the impossible: when even straight top grades fall short it says so and gives the best that can
 * still be reached. When continuous assessment marks are already in (and the school lets students see them) it works
 * out the exam score each grade needs and leaves out grades the exam can no longer deliver.
 */
class GpaPlanner
{
    public function __construct(private MySchedule $schedule, private MobileAppSettings $settings, private AcademicRules $rules, private \App\Services\RegistrationService $registration)
    {
    }

    /** The grading scale, the student's record so far and this semester's courses. */
    public function context(Student $student): array
    {
        $term = $this->schedule->term();
        $ids = $term->sessionId ? $this->schedule->courses($student, $term) : [];
        $units = $ids ? CourseRegistration::query()->join('registration_forms as f', 'f.id', '=', 'course_registrations.registration_form_id')
            ->where('f.student_id', $student->id)->where('f.academic_session_id', $term->sessionId)->where('f.semester_type_id', $term->semesterTypeId)
            ->whereIn('course_registrations.course_id', $ids)->pluck('course_registrations.credit_units', 'course_registrations.course_id') : collect();
        $courses = Course::whereIn('id', $ids)->orderBy('code')->get();

        $running = collect();
        if ($this->settings->on('show_running_ca') && $ids) {
            $running = CourseResult::with('sheet.scheme.items')->where('student_id', $student->id)->where('academic_session_id', $term->sessionId)
                ->where('semester_type_id', $term->semesterTypeId)->whereIn('course_id', $ids)->get()->keyBy('course_id');
        }

        [$tnu, $tcp, $byCourse, $method] = $this->record($student);
        $outstanding = $this->registration->outstanding($student);
        $outstandingIds = $outstanding->map(fn ($o) => $o['course']->id)->flip();

        return [
            'term' => $term->semester ? trim(($term->semester->session?->name ?? '') . ' · ' . $term->semester->name, ' ·') : null,
            'scale' => $this->scale(),
            'max_points' => GradeScale::maxPoints(),
            'cgpa' => $tnu > 0 ? round($tcp / $tnu, 2) : 0.0,
            'units' => $tnu,
            'points' => round($tcp, 2),
            'method' => $method,
            // Failed courses still to pass: the planner and the reading plan give them extra weight.
            'outstanding' => $outstanding->map(fn ($o) => ['id' => $o['course']->id, 'code' => $o['course']->code, 'title' => $o['course']->title,
                'units' => (int) $o['course']->credit_units, 'last_grade' => $o['last_grade'], 'offered_in' => $o['semester'], 'registered_now' => $o['chosen_now']])->values()->all(),
            'courses' => $courses->map(function (Course $c) use ($units, $running, $byCourse, $outstandingIds) {
                $row = $running->get($c->id);
                [$caMax, $examMax] = $row?->sheet ? ResultService::limits($row->sheet) : [ResultService::caMax(), ResultService::examMax()];
                $prev = $byCourse->get($c->id);

                return [
                    'id' => $c->id, 'code' => $c->code, 'title' => $c->title,
                    'units' => (int) ($units[$c->id] ?? $c->credit_units),
                    'ca' => $row && $row->ca !== null && $row->status !== 'published' ? (float) $row->ca : null,
                    'ca_max' => (float) $caMax, 'exam_max' => (float) $examMax,
                    'retake_of' => $prev ? ['grade' => $prev['grade'], 'points' => $prev['points'], 'units' => $prev['units']] : null,
                    'carryover' => $prev !== null && $outstandingIds->has($c->id),
                ];
            })->values()->all(),
        ];
    }

    /** @return array<int, array{letter: string, min: float, max: float, points: float, pass: bool}> highest first */
    public function scale(): array
    {
        return GradeScale::bands()->map(fn (GradeBand $b) => ['letter' => $b->letter, 'min' => (float) $b->min_score, 'max' => (float) $b->max_score, 'points' => (float) $b->points, 'pass' => (bool) $b->is_pass])->values()->all();
    }

    /**
     * Grade plans that reach the target.
     *
     * @param  array  $ctx  from context() (scale, units, points, method, courses)
     * @param  array<int, int>  $strengths  course id => 1 (weak) .. 5 (strong)
     * @param  array<int, string>  $fixed  course id => letter the student already knows or insists on
     */
    public function plan(array $ctx, float $target, string $kind, array $strengths = [], array $fixed = []): array
    {
        $levels = collect($ctx['scale'])->where('pass', true)->unique('points')->sortByDesc('points')->values();   // A, B, C ... (passing only)
        $courses = collect($ctx['courses'])->values();
        $maxPoints = (float) ($levels->first()['points'] ?? 0);
        $U = (int) $courses->sum('units');
        if ($levels->isEmpty() || $U === 0) {
            return ['feasible' => false, 'message' => $U === 0 ? 'You have no courses for this semester yet. Register your courses first.' : 'The grading scale has not been set up yet.', 'scenarios' => []];
        }
        if ($target <= 0 || $target > $maxPoints) {
            return ['feasible' => false, 'message' => 'Enter a target between 0.01 and ' . rtrim(rtrim(number_format($maxPoints, 2), '0'), '.') . '.', 'scenarios' => []];
        }

        // With "latest attempt counts", a retaken course's earlier result leaves the CGPA when the new one arrives.
        $baseUnits = (int) $ctx['units'];
        $basePoints = (float) $ctx['points'];
        if (($ctx['method'] ?? 'latest') !== 'all') {
            foreach ($courses as $c) {
                if ($c['retake_of'] && $c['retake_of']['points'] !== null) {
                    $baseUnits -= (int) $c['retake_of']['units'];
                    $basePoints -= (float) $c['retake_of']['points'] * (int) $c['retake_of']['units'];
                }
            }
        }

        $need = $kind === 'cgpa' ? $target * ($baseUnits + $U) - $basePoints : $target * $U;   // grade points x units this semester
        $need -= 1e-9;

        // The best level each course can still reach: the exam may no longer be able to lift a course to an A.
        $best = [];
        $fixedLevel = [];
        foreach ($courses as $i => $c) {
            $best[$i] = 0;
            if ($c['ca'] !== null) {
                $possible = $c['ca'] + $c['exam_max'];
                $idx = $levels->search(fn ($l) => $this->minScore($ctx['scale'], $l['points']) <= $possible + 1e-9);
                $best[$i] = $idx === false ? $levels->count() - 1 : $idx;
            }
            if (isset($fixed[$c['id']])) {
                $p = collect($ctx['scale'])->firstWhere('letter', $fixed[$c['id']])['points'] ?? null;
                $idx = $p === null ? false : $levels->search(fn ($l) => abs($l['points'] - $p) < 1e-9);
                if ($idx !== false) {
                    $fixedLevel[$i] = $idx;
                }
            }
        }

        $lowest = $levels->count() - 1;
        $points = fn (array $lv) => $courses->keys()->sum(fn ($i) => $levels[$lv[$i]]['points'] * $courses[$i]['units']);
        $start = fn (int $level) => $courses->keys()->mapWithKeys(fn ($i) => [$i => $fixedLevel[$i] ?? max($level, $best[$i])])->all();

        $top = $start(0);
        $maxSem = $points($top);
        $result = [
            'target' => $target, 'kind' => $kind,
            'needed_gpa' => round(($need + 1e-9) / $U, 2),
            'max_gpa' => round($maxSem / $U, 2),
            'max_cgpa' => ($baseUnits + $U) > 0 ? round(($basePoints + $maxSem) / ($baseUnits + $U), 2) : null,
        ];

        if ($maxSem < $need) {
            return $result + ['feasible' => false, 'scenarios' => [$this->scenario('best', 'The best you can still reach', 'Even with these grades the target is out of reach this semester.', $top, $courses, $levels, $ctx, $baseUnits, $basePoints, $U)],
                'message' => sprintf('A %s of %s is out of reach this semester: the most you can reach is %s. Aim for that, and plan the rest over the coming semesters.',
                    strtoupper($kind), number_format($target, 2), number_format($kind === 'cgpa' ? $result['max_cgpa'] : $result['max_gpa'], 2))];
        }

        $floor = $start($lowest);
        if ($points($floor) >= $need) {
            return $result + ['feasible' => true, 'already' => true, 'scenarios' => [$this->scenario('pass', 'Passing every course is enough', 'Any pass in every course keeps you at or above the target, but aim higher to be safe.', $floor, $courses, $levels, $ctx, $baseUnits, $basePoints, $U)],
                'message' => 'You reach this target as long as you pass every course.'];
        }

        $free = fn () => $courses->keys()->reject(fn ($i) => isset($fixedLevel[$i]));
        $scenarios = [];

        // 1. Balanced: the same grade everywhere as far as possible, raising the heaviest courses first.
        $lv = null;
        for ($L = $lowest; $L >= 0; $L--) {
            $try = $start($L);
            if ($points($try) >= $need) {
                $lv = $start(min($lowest, $L + 1));
                foreach ($free()->sortByDesc(fn ($i) => $courses[$i]['units'])->values() as $i) {
                    if ($points($lv) >= $need) {
                        break;
                    }
                    $lv[$i] = max($best[$i], $L);
                }
                // Lower anything still above what is needed, smallest courses first.
                $lv = $this->trim($lv, $free()->sortBy(fn ($i) => $courses[$i]['units'])->values()->all(), $best, $lowest, $points, $need);
                break;
            }
        }
        if ($lv) {
            $scenarios[] = $this->scenario('balanced', 'Balanced', 'Much the same grade in every course; the heavier courses carry the extra.', $lv, $courses, $levels, $ctx, $baseUnits, $basePoints, $U);
        }

        // 2. Focus on the courses with the most credit units: they move the GPA most.
        $scenarios[] = $this->scenario('units', 'Focus on high-unit courses', 'Top grades where the units are, so the other courses can be lighter.',
            $this->greedy($start($lowest), $free()->sortByDesc(fn ($i) => [$courses[$i]['units'], -$i])->values()->all(), $best, $points, $need, $lowest),
            $courses, $levels, $ctx, $baseUnits, $basePoints, $U);

        // 3. Play to strengths, when the student said which courses they are good at.
        if ($strengths) {
            $order = $free()->sortByDesc(fn ($i) => [(int) ($strengths[$courses[$i]['id']] ?? 3), $courses[$i]['units']])->values()->all();
            $scenarios[] = $this->scenario('strengths', 'Play to your strengths', 'Higher grades in the courses you are strongest in, a safe pass in the hardest.',
                $this->greedy($start($lowest), $order, $best, $points, $need, $lowest), $courses, $levels, $ctx, $baseUnits, $basePoints, $U);
        }

        $unique = collect($scenarios)->unique(fn ($s) => collect($s['courses'])->pluck('grade')->implode(','))->values()->all();

        return $result + ['feasible' => true, 'scenarios' => $unique, 'message' => $kind === 'cgpa'
            ? sprintf('To reach a CGPA of %s you need a GPA of at least %s this semester.', number_format($target, 2), number_format($result['needed_gpa'], 2))
            : sprintf('Here are ways to reach a GPA of %s this semester.', number_format($target, 2))];
    }

    /** Raise courses in the given order, one grade at a time up to their best, until the target is met; then trim. */
    private function greedy(array $lv, array $order, array $best, callable $points, float $need, int $lowest): array
    {
        foreach ($order as $i) {
            while ($lv[$i] > $best[$i] && $points($lv) < $need) {
                $lv[$i]--;
            }
            if ($points($lv) >= $need) {
                break;
            }
        }

        return $this->trim($lv, array_reverse($order), $best, $lowest, $points, $need);
    }

    /** Lower grades (in the order given) wherever the target is still met without them. */
    private function trim(array $lv, array $order, array $best, int $lowest, callable $points, float $need): array
    {
        foreach ($order as $i) {
            while ($lv[$i] < $lowest) {
                $lv[$i]++;
                if ($points($lv) < $need) {
                    $lv[$i]--;
                    break;
                }
            }
        }

        return $lv;
    }

    private function scenario(string $key, string $label, string $note, array $lv, Collection $courses, Collection $levels, array $ctx, int $baseUnits, float $basePoints, int $U): array
    {
        $sem = 0.0;
        $rows = [];
        foreach ($courses as $i => $c) {
            $l = $levels[$lv[$i]];
            $sem += $l['points'] * $c['units'];
            $min = $this->minScore($ctx['scale'], $l['points']);
            $exam = $c['ca'] !== null ? max(0, round($min - $c['ca'], 2)) : null;
            $rows[] = ['id' => $c['id'], 'code' => $c['code'], 'title' => $c['title'], 'units' => $c['units'], 'grade' => $l['letter'], 'points' => $l['points'],
                'min_score' => $min, 'ca' => $c['ca'], 'exam_needed' => $exam, 'exam_max' => $c['exam_max']];
        }

        return ['key' => $key, 'label' => $label, 'note' => $note, 'courses' => $rows, 'gpa' => round($sem / $U, 2),
            'cgpa' => ($baseUnits + $U) > 0 ? round(($basePoints + $sem) / ($baseUnits + $U), 2) : null];
    }

    /** The lowest score that earns a grade worth these points. */
    private function minScore(array $scale, float $points): float
    {
        return (float) collect($scale)->filter(fn ($b) => abs($b['points'] - $points) < 1e-9)->min('min');
    }

    /** Graded units, grade points and the counted result per course, following the CGPA rule (all attempts or the latest). */
    private function record(Student $student): array
    {
        $all = CourseResult::with('session', 'semesterType')->where('student_id', $student->id)->where('status', 'published')->get()
            ->sortBy(fn ($r) => [$r->session?->name, $r->semesterType?->sort_order, $r->id])->values();
        $method = $this->rules->forStudent($student)['cgpa_method'] ?? 'latest';
        $latest = $all->groupBy('course_id')->map->last();
        $counted = ($method === 'all' ? $all : $latest->values())->filter(fn ($r) => $r->grade !== null && $r->points !== null);
        $bf = $student->legacyBalance;
        $tnu = (int) $counted->sum('credit_units') + (int) ($bf?->tnu ?? 0);
        $tcp = (float) $counted->sum(fn ($r) => $r->points * $r->credit_units) + (float) ($bf?->tcp ?? 0);
        $byCourse = $latest->filter(fn ($r) => $r->grade !== null)->map(fn ($r) => ['grade' => $r->grade, 'points' => $r->points !== null ? (float) $r->points : null, 'units' => (int) $r->credit_units]);

        return [$tnu, $tcp, $byCourse, $method];
    }
}
