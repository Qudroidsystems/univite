<?php

namespace App\Services;

use App\Models\CourseRegistration;
use App\Models\ExamSeat;
use App\Models\SeatingPlan;
use App\Models\Student;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seats every candidate of every paper in an examination timetable. The timetable decides when and in which hall a
 * paper is written; this decides which candidate sits in which seat of that hall.
 *
 * Several papers can share a hall: seats are filled in order and each seat takes the paper with the most candidates
 * still to seat that is not already next to it (by the chosen separation rule). When nothing else fits, the seat is
 * left empty if the hall has room to spare. The final arrangement is then checked seat by seat and anything the rule
 * could not keep is reported, never hidden. A candidate with two papers at the same time is flagged, not seated twice.
 */
class SeatingService
{
    public const DEFAULT_RULES = ['pattern' => 'side', 'order' => 'matric', 'reserve_accessible' => true];

    public function __construct(private OperationAudit $audit, private Messenger $messenger)
    {
    }

    /** Candidates per paper: everyone with the course on a course form for that semester that was not returned. */
    public function candidates(Timetable $t, array $courseIds): Collection
    {
        return CourseRegistration::query()->join('registration_forms as f', 'f.id', '=', 'course_registrations.registration_form_id')
            ->where('f.academic_session_id', $t->academic_session_id)->where('f.semester_type_id', $t->semester_type_id)->where('f.status', '!=', 'rejected')
            ->whereIn('course_registrations.course_id', $courseIds)->get(['f.student_id', 'course_registrations.course_id'])
            ->groupBy('course_id')->map(fn ($r) => $r->pluck('student_id')->unique()->values());
    }

    public function generate(Timetable $t, array $rules, User $by): SeatingPlan
    {
        if (! $t->isExam()) {
            throw new RuntimeException('Seating is only for examination timetables.');
        }
        if (! in_array($t->status, ['approved', 'published'], true)) {
            throw new RuntimeException('Approve the examination timetable before seating candidates, so the halls and times are final.');
        }
        $rules = array_merge(self::DEFAULT_RULES, array_intersect_key($rules, self::DEFAULT_RULES));
        @set_time_limit(300);

        return DB::transaction(function () use ($t, $rules, $by) {
            $t->seatingPlans()->whereIn('status', ['draft', 'approved'])->update(['status' => 'superseded']);
            $plan = SeatingPlan::create(['timetable_id' => $t->id, 'rules' => $rules, 'generated_by' => $by->id, 'generated_at' => now()]);

            $entries = $t->entries()->with('venue', 'course')->whereNotNull('venue_id')->orderBy('id')->get();
            $cands = $this->candidates($t, $entries->pluck('course_id')->unique()->all());
            $students = Student::whereIn('id', $cands->flatten()->unique())->get(['id', 'matric_number', 'needs_accessible_seat', 'last_name', 'first_name'])->keyBy('id');

            // A candidate with two papers in one sitting is a timetable clash: seat the first paper, report the rest.
            $clashes = [];
            $taken = [];
            $perPart = [];
            foreach ($entries->groupBy(fn ($e) => $e->slots[0]) as $slot => $rows) {
                foreach ($rows->groupBy('course_id') as $courseId => $parts) {
                    $list = ($cands[$courseId] ?? collect())->filter(function ($sid) use (&$taken, $slot, $courseId, &$clashes, $parts) {
                        if (isset($taken[$slot][$sid]) && $taken[$slot][$sid] !== $courseId) {
                            $clashes[] = ['student' => $sid, 'slot' => $slot, 'papers' => [$taken[$slot][$sid], $courseId]];

                            return false;
                        }
                        $taken[$slot][$sid] = $courseId;

                        return true;
                    });
                    $list = $rules['order'] === 'random' ? $list->shuffle() : $list->sortBy(fn ($sid) => $students[$sid]->matric_number ?? 'zzz' . $sid);
                    $list = $list->values();
                    $offset = 0;
                    foreach ($parts->values() as $i => $part) {
                        $n = $i === $parts->count() - 1 ? $list->count() - $offset : min($part->seats, $list->count() - $offset);
                        $perPart[$part->id] = $list->slice($offset, max(0, $n))->values()->all();
                        $offset += max(0, $n);
                    }
                }
            }

            $halls = [];
            foreach ($entries->groupBy(fn ($e) => $e->slots[0] . '|' . $e->venue_id) as $rows) {
                $halls[] = $this->seatHall($plan, $rows, $perPart, $students, $rules);
            }

            $summary = [
                'halls' => count($halls), 'seated' => array_sum(array_column($halls, 'seated')), 'unseated' => array_sum(array_column($halls, 'unseated')),
                'adjacent' => array_sum(array_column($halls, 'adjacent')), 'problems' => array_merge(...array_map(fn ($h) => $h['problems'], $halls ?: [['problems' => []]])),
                'clashes' => array_map(fn ($c) => ($students[$c['student']]->matric_number ?? '#' . $c['student']) . ' has two papers at ' . $c['slot'], array_slice($clashes, 0, 50)),
                'clash_count' => count($clashes),
            ];
            $plan->update(['summary' => $summary]);

            $op = $this->audit->begin('seating_generate', 'seating', $by, $t->academic_session_id, null, ['timetable' => $t->id, 'plan' => $plan->id]);
            $this->audit->record($op, 'generated', $t, null, ['seated' => $summary['seated'], 'adjacent' => $summary['adjacent'], 'clashes' => $summary['clash_count']], null, null, 'Seating · ' . $t->title);
            $this->audit->finish($op, $summary['seated'], 0, $summary['unseated']);

            return $plan;
        });
    }

    /** Seat the candidates of every paper sharing one hall at one sitting. */
    private function seatHall(SeatingPlan $plan, Collection $rows, array $perPart, Collection $students, array $rules): array
    {
        /** @var Venue $venue */
        $venue = $rows->first()->venue;
        $first = $rows->first();
        $map = $venue->seatMap();
        $queues = [];
        $accessQueue = [];
        foreach ($rows as $part) {
            foreach ($perPart[$part->id] ?? [] as $sid) {
                if ($rules['reserve_accessible'] && ($students[$sid]->needs_accessible_seat ?? false)) {
                    $accessQueue[] = [$sid, $part];
                } else {
                    $queues[$part->id][] = $sid;
                }
            }
        }
        $total = array_sum(array_map('count', $queues)) + count($accessQueue);
        $placed = [];   // "r-c" => course id
        $out = [];
        $problems = [];
        $parts = $rows->keyBy('id');

        // Accessible seats first, for the candidates who need them.
        $accessible = array_values(array_filter($map, fn ($s) => $s['accessible']));
        foreach ($accessQueue as $i => [$sid, $part]) {
            if ($seat = $accessible[$i] ?? null) {
                $placed[$seat['row'] . '-' . $seat['col']] = $part->course_id;
                $out[] = [$seat, $sid, $part];
            } else {
                $queues[$part->id][] = $sid;
                $problems[] = "{$venue->name}: not enough accessible seats; a candidate who needs one was given an ordinary seat.";
            }
        }
        $free = array_values(array_filter($map, fn ($s) => ! isset($placed[$s['row'] . '-' . $s['col']]) && (! $rules['reserve_accessible'] || ! $s['accessible'] || $total > count($map) - count($accessible))));

        $left = array_sum(array_map('count', $queues));
        foreach ($free as $i => $seat) {
            if ($left <= 0) {
                break;
            }
            $seatsLeft = count($free) - $i;
            $near = $this->neighbourCourses($seat, $placed, $rules['pattern']);
            $choice = null;
            $best = -1;
            foreach ($queues as $pid => $q) {
                $n = count($q);
                if ($n > 0 && ! in_array($parts[$pid]->course_id, $near, true) && $n > $best) {
                    $best = $n;
                    $choice = $pid;
                }
            }
            if ($choice === null) {
                if ($seatsLeft - 1 >= $left) {
                    continue; // leave it empty as a buffer
                }
                $choice = collect($queues)->map(fn ($q) => count($q))->sortDesc()->keys()->first();
            }
            $sid = array_shift($queues[$choice]);
            $placed[$seat['row'] . '-' . $seat['col']] = $parts[$choice]->course_id;
            $out[] = [$seat, $sid, $parts[$choice]];
            $left--;
        }

        $codes = [];
        foreach ($out as $n => [$seat, $sid, $part]) {
            do {
                $code = strtoupper(Str::random(10));
            } while (isset($codes[$code]) || ExamSeat::where('code', $code)->exists());
            $codes[$code] = true;
            ExamSeat::create([
                'seating_plan_id' => $plan->id, 'timetable_entry_id' => $part->id, 'venue_id' => $venue->id, 'course_id' => $part->course_id, 'student_id' => $sid,
                'slot' => $first->slots[0], 'date' => $first->date, 'starts_at' => $part->starts_at, 'ends_at' => $part->ends_at,
                'seat_label' => $seat['label'], 'seat_row' => $seat['row'], 'seat_col' => $seat['col'], 'seat_no' => array_search($seat, $map, true) + 1, 'code' => $code,
            ]);
        }

        $adjacent = $this->adjacentPairs($placed, $rules['pattern']);
        if ($adjacent) {
            $problems[] = "{$venue->name} ({$first->date?->format('j M')} {$first->starts_at}): {$adjacent} pair(s) of candidates of the same paper sit next to each other; the hall is too full of one paper to separate them all.";
        }
        if ($left > 0) {
            $problems[] = "{$venue->name} ({$first->date?->format('j M')} {$first->starts_at}): {$left} candidate(s) could not be seated; the seat map has fewer usable seats than the timetable allocated.";
        }

        return ['seated' => count($out), 'unseated' => max(0, $left), 'adjacent' => $adjacent, 'problems' => $problems];
    }

    private function offsets(string $pattern): array
    {
        $side = [[0, -1], [0, 1]];

        return match ($pattern) {
            'side_front' => array_merge($side, [[-1, 0], [1, 0]]),
            'diagonal' => array_merge($side, [[-1, 0], [1, 0], [-1, -1], [-1, 1], [1, -1], [1, 1]]),
            default => $side,
        };
    }

    private function neighbourCourses(array $seat, array $placed, string $pattern): array
    {
        $out = [];
        foreach ($this->offsets($pattern) as [$dr, $dc]) {
            $k = ($seat['row'] + $dr) . '-' . ($seat['col'] + $dc);
            isset($placed[$k]) && $out[] = $placed[$k];
        }

        return $out;
    }

    private function adjacentPairs(array $placed, string $pattern): int
    {
        $n = 0;
        foreach ($placed as $k => $course) {
            [$r, $c] = array_map('intval', explode('-', $k));
            foreach ($this->offsets($pattern) as [$dr, $dc]) {
                if (($placed[($r + $dr) . '-' . ($c + $dc)] ?? null) === $course) {
                    $n++;
                }
            }
        }

        return intdiv($n, 2);
    }

    /** Recount same-paper neighbours for a plan as it stands (after moves). */
    public function validate(SeatingPlan $plan): int
    {
        $total = 0;
        foreach ($plan->seats()->get()->groupBy(fn ($s) => $s->slot . '|' . $s->venue_id) as $seats) {
            $placed = $seats->mapWithKeys(fn ($s) => [$s->seat_row . '-' . $s->seat_col => $s->course_id])->all();
            $total += $this->adjacentPairs($placed, $plan->rules['pattern'] ?? 'side');
        }

        return $total;
    }

    // ---- registration changes, moves and attendance ---------------------------------------------------

    /** Candidates registered since the plan was made (no seat) and seats whose candidate is no longer registered. */
    public function drift(SeatingPlan $plan): array
    {
        $t = $plan->timetable;
        $courseIds = $t->entries()->distinct()->pluck('course_id')->all();
        $now = $this->candidates($t, $courseIds);
        $seated = $plan->seats()->get(['id', 'student_id', 'course_id'])->groupBy('course_id');
        $missing = [];
        $gone = [];
        foreach ($courseIds as $cid) {
            $reg = ($now[$cid] ?? collect())->all();
            $have = ($seated[$cid] ?? collect())->pluck('student_id')->all();
            foreach (array_diff($reg, $have) as $sid) {
                $missing[] = ['student_id' => $sid, 'course_id' => $cid];
            }
            foreach (($seated[$cid] ?? collect())->whereNotIn('student_id', $reg) as $seat) {
                $gone[] = $seat->id;
            }
        }

        return ['missing' => $missing, 'gone' => $gone];
    }

    /** Seat late registrants in a free seat of their paper's hall, away from candidates of the same paper where possible. */
    public function seatLate(SeatingPlan $plan, User $by): int
    {
        $this->changeable($plan);
        $n = 0;
        DB::transaction(function () use ($plan, $by, &$n) {
            $op = $this->audit->begin('seating_move', 'seating', $by, $plan->timetable->academic_session_id, 'Seat candidates who registered after the plan was made', ['plan' => $plan->id]);
            foreach ($this->drift($plan)['missing'] as $m) {
                $entries = $plan->timetable->entries()->with('venue')->where('course_id', $m['course_id'])->whereNotNull('venue_id')->get();
                foreach ($entries as $e) {
                    if ($seat = $this->freeSeat($plan, $e->venue, $e->slots[0], $m['course_id'])) {
                        $row = $this->createSeat($plan, $e, $m['student_id'], $seat);
                        $this->audit->record($op, 'seated', $row->student, null, ['seat' => $row->seat_number, 'course' => $e->course?->code]);
                        $this->tell($plan, $row, 'You have been given an examination seat');
                        $n++;
                        break;
                    }
                }
            }
            $this->audit->finish($op, $n);
        });

        return $n;
    }

    public function releaseGone(SeatingPlan $plan, User $by): int
    {
        $this->changeable($plan);
        $ids = $this->drift($plan)['gone'];
        if ($ids) {
            $op = $this->audit->begin('seating_move', 'seating', $by, $plan->timetable->academic_session_id, 'Release seats of candidates no longer registered', ['plan' => $plan->id]);
            foreach (ExamSeat::with('student', 'course')->whereIn('id', $ids)->get() as $seat) {
                $this->audit->record($op, 'released', $seat->student, ['seat' => $seat->seat_number, 'course' => $seat->course?->code], null);
                $seat->delete();
            }
            $this->audit->finish($op, count($ids));
        }

        return count($ids);
    }

    private function freeSeat(SeatingPlan $plan, Venue $venue, string $slot, int $courseId): ?array
    {
        $taken = $plan->seats()->where('venue_id', $venue->id)->where('slot', $slot)->get()->mapWithKeys(fn ($s) => [$s->seat_row . '-' . $s->seat_col => $s->course_id])->all();
        $fallback = null;
        foreach ($venue->seatMap() as $seat) {
            if (isset($taken[$seat['row'] . '-' . $seat['col']])) {
                continue;
            }
            if (! in_array($courseId, $this->neighbourCourses($seat, $taken, $plan->rules['pattern'] ?? 'side'), true)) {
                return $seat;
            }
            $fallback ??= $seat;
        }

        return $fallback;
    }

    private function createSeat(SeatingPlan $plan, TimetableEntry $e, int $studentId, array $seat): ExamSeat
    {
        do {
            $code = strtoupper(Str::random(10));
        } while (ExamSeat::where('code', $code)->exists());

        return ExamSeat::create([
            'seating_plan_id' => $plan->id, 'timetable_entry_id' => $e->id, 'venue_id' => $e->venue_id, 'course_id' => $e->course_id, 'student_id' => $studentId,
            'slot' => $e->slots[0], 'date' => $e->date, 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at, 'seat_label' => $seat['label'],
            'seat_row' => $seat['row'], 'seat_col' => $seat['col'], 'seat_no' => (int) (collect($e->venue->seatMap())->search(fn ($s) => $s['label'] === $seat['label']) + 1), 'code' => $code,
        ]);
    }

    /**
     * Move a candidate to another free seat in one of the halls their paper uses at that sitting. Refused if the seat is
     * taken, not on the map, or (unless accepted) next to a candidate of the same paper.
     */
    public function move(ExamSeat $seat, int $venueId, string $label, string $reason, User $by, bool $acceptAdjacent = false): ExamSeat
    {
        $plan = $seat->plan;
        $this->changeable($plan);
        $entry = $plan->timetable->entries()->with('venue')->where('course_id', $seat->course_id)->where('venue_id', $venueId)->get()->first(fn ($e) => $e->slots[0] === $seat->slot);
        if (! $entry) {
            throw new RuntimeException('That hall is not used for this paper at this sitting.');
        }
        $target = collect($entry->venue->seatMap())->firstWhere('label', strtoupper($label));
        if (! $target) {
            throw new RuntimeException("Seat {$label} is not a usable seat in {$entry->venue->name}.");
        }
        $taken = $plan->seats()->where('venue_id', $venueId)->where('slot', $seat->slot)->where('id', '!=', $seat->id)->get()
            ->mapWithKeys(fn ($s) => [$s->seat_row . '-' . $s->seat_col => $s->course_id])->all();
        if (isset($taken[$target['row'] . '-' . $target['col']])) {
            throw new RuntimeException("Seat {$target['label']} is already taken.");
        }
        if (! $acceptAdjacent && in_array($seat->course_id, $this->neighbourCourses($target, $taken, $plan->rules['pattern'] ?? 'side'), true)) {
            throw new RuntimeException("Seat {$target['label']} is next to another candidate of the same paper. Choose another seat, or tick the box to accept it.");
        }

        return DB::transaction(function () use ($seat, $entry, $target, $reason, $by, $plan) {
            $before = ['seat' => $seat->seat_number];
            $seat->update([
                'venue_id' => $entry->venue_id, 'timetable_entry_id' => $entry->id, 'seat_label' => $target['label'], 'seat_row' => $target['row'], 'seat_col' => $target['col'],
                'seat_no' => (int) (collect($entry->venue->seatMap())->search(fn ($s) => $s['label'] === $target['label']) + 1), 'moved_from' => $before['seat'],
            ]);
            $seat = $seat->fresh(['venue', 'student', 'course']);
            $op = $this->audit->begin('seating_move', 'seating', $by, $plan->timetable->academic_session_id, $reason, ['plan' => $plan->id]);
            $this->audit->record($op, 'moved', $seat->student, $before, ['seat' => $seat->seat_number], $reason, null, trim(($seat->student?->full_name ?? '') . ' · ' . $seat->course?->code));
            $this->audit->finish($op, 1);
            $this->tell($plan, $seat, 'Your examination seat has changed');

            return $seat;
        });
    }

    public function attendance(ExamSeat $seat, ?string $mark, User $by): void
    {
        $seat->update(['attendance' => $mark, 'checked_at' => $mark ? now() : null, 'checked_by' => $mark ? $by->id : null]);
    }

    private function tell(SeatingPlan $plan, ExamSeat $seat, string $title): void
    {
        if ($plan->status === 'published' && ($user = $seat->student?->user)) {
            $seat->loadMissing('venue', 'course');
            $this->messenger->notify($user, 'seating', $title, "{$seat->course?->code} on {$seat->date->format('D j M')} at " . substr($seat->starts_at, 0, 5) . ": {$seat->venue?->name}, seat {$seat->seat_number}.", $seat, route('my.exams'), 'info');
        }
    }

    private function changeable(SeatingPlan $plan): void
    {
        if ($plan->status === 'superseded') {
            throw new RuntimeException('This seating plan has been replaced.');
        }
    }

    // ---- approval and publication ---------------------------------------------------------------------

    public function approve(SeatingPlan $plan, User $by): void
    {
        if ($plan->status !== 'draft') {
            throw new RuntimeException('Only a draft seating plan can be approved.');
        }
        $plan->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()]);
        $this->audit->note('seating_approve', 'seating', $by, 'approved', $plan->timetable, ['status' => 'draft'], ['status' => 'approved'], null, $plan->timetable->academic_session_id);
    }

    public function publish(SeatingPlan $plan, User $by): int
    {
        if ($plan->status !== 'approved') {
            throw new RuntimeException('Approve the seating plan before publishing it.');
        }
        if ($plan->timetable->status !== 'published') {
            throw new RuntimeException('Publish the examination timetable first; seat numbers are shown with it.');
        }

        return DB::transaction(function () use ($plan, $by) {
            $plan->timetable->seatingPlans()->where('status', 'published')->where('id', '!=', $plan->id)->update(['status' => 'superseded']);
            $plan->update(['status' => 'published', 'published_by' => $by->id, 'published_at' => now()]);
            $this->audit->note('seating_publish', 'seating', $by, 'published', $plan->timetable, ['status' => 'approved'], ['status' => 'published', 'seats' => $plan->seats()->count()], null, $plan->timetable->academic_session_id);
            $n = 0;
            $users = Student::whereIn('id', $plan->seats()->distinct()->pluck('student_id'))->with('user')->get()->pluck('user')->filter();
            foreach ($users as $u) {
                $this->messenger->notify($u, 'seating', 'Your examination seat numbers', 'Your hall and seat number for each paper are now on your portal under My Examinations.', $plan, route('my.exams'), 'info', "seat{$plan->id}-u{$u->id}");
                $n++;
            }

            return $n;
        });
    }

    /** The published seats of a student, by paper. */
    public static function seatsFor(Student $student): Collection
    {
        return ExamSeat::with('venue', 'course', 'plan.timetable')->where('student_id', $student->id)
            ->whereHas('plan', fn ($q) => $q->where('status', 'published'))->orderBy('date')->orderBy('starts_at')->get();
    }
}
