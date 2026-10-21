<?php

namespace App\Timetabling;

use App\Models\CourseRegistration;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\ProgrammeCourse;
use App\Models\StaffUnavailability;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Models\Timetable;
use App\Models\TimetableEntry;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueBooking;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/** Turns a timetable (its settings, items, venues and everyone's availability) into a Problem for the solver. */
class ProblemBuilder
{
    /**
     * @param  array  $whatIf  close_venues => [ids], capacity => [id => seats], extra_dates => [Y-m-d], extra_days => [1-7]
     */
    public function build(Timetable $t, array $whatIf = []): Problem
    {
        $p = new Problem;
        $p->kind = $t->kind;
        $c = $t->config ?? [];
        $p->weights = collect(Timetable::PREFERENCES)->mapWithKeys(fn ($d, $k) => [$k => $t->weight($k)])->all();
        $p->maxConsecutive = (int) ($c['max_consecutive'] ?? 3);
        $p->maxSplit = max(1, (int) ($c['max_split'] ?? 4));
        $p->seed = (int) ($c['seed'] ?? 1);
        $p->maxPerDay = max(0, (int) ($c['max_per_day'] ?? 0));
        $p->applyPriorities = (bool) ($c['apply_priorities'] ?? true);
        $p->strictRooms = (bool) ($c['strict_rooms'] ?? false);

        $t->isExam() ? $this->examSlots($p, $c, $whatIf) : $this->lectureSlots($p, $c, $whatIf);
        $p->finalise();

        $items = $t->items()->with('course')->where('is_active', true)->get();
        $this->units($p, $t, $items);
        $this->conflicts($p, $t, $items);
        $this->venues($p, $t, $whatIf);
        $this->staff($p, $t, $items);

        foreach ($t->entries()->where('locked', true)->get()->groupBy('unit_key') as $key => $rows) {
            if (isset($p->units[$key])) {
                $p->fixed[$key] = ['slots' => $rows->first()->slots, 'venues' => $rows->filter(fn ($r) => $r->venue_id)->mapWithKeys(fn ($r) => [$r->venue_id => (int) $r->seats])->all()];
            }
        }

        return $p;
    }

    // ---- time -----------------------------------------------------------------------------------------

    private function lectureSlots(Problem $p, array $c, array $whatIf): void
    {
        $days = array_values(array_unique(array_merge($c['days'] ?? [1, 2, 3, 4, 5], $whatIf['extra_days'] ?? [])));
        sort($days);
        foreach ($days as $day) {
            foreach ($c['periods'] ?? [] as [$start, $end]) {
                $key = 'd' . $day . '-' . str_replace(':', '', $start);
                $p->slots[$key] = ['key' => $key, 'group' => 'd' . $day, 'day' => (int) $day, 'date' => null, 'start' => $start, 'end' => $end, 'minutes' => self::minutes($start, $end), 'pos' => 0, 'order' => 0];
                foreach ($c['blocked'] ?? [] as $b) {
                    if ((int) ($b['day'] ?? 0) === (int) $day && self::overlaps($start, $end, $b['start'] ?? null, $b['end'] ?? null)) {
                        $p->protected[$key] = $b['label'] ?? 'Protected period';
                    }
                }
            }
        }
    }

    private function examSlots(Problem $p, array $c, array $whatIf): void
    {
        $dates = [];
        if (! empty($c['start_date']) && ! empty($c['end_date'])) {
            $excluded = array_flip($c['excluded_dates'] ?? []);
            foreach (CarbonPeriod::create($c['start_date'], $c['end_date']) as $d) {
                if (in_array($d->dayOfWeekIso, $c['days'] ?? [1, 2, 3, 4, 5], true) && ! isset($excluded[$d->toDateString()]) && ! Holiday::nameOn($d)) {
                    $dates[] = $d->toDateString();
                }
            }
        }
        $dates = array_values(array_unique(array_merge($dates, $whatIf['extra_dates'] ?? [])));
        sort($dates);
        foreach ($dates as $date) {
            $dow = Carbon::parse($date)->dayOfWeekIso;
            foreach ($c['sittings'] ?? [] as [$start, $end]) {
                $key = $date . '-' . str_replace(':', '', $start);
                $p->slots[$key] = ['key' => $key, 'group' => $date, 'day' => $dow, 'date' => $date, 'start' => $start, 'end' => $end, 'minutes' => self::minutes($start, $end), 'pos' => 0, 'order' => 0];
                foreach ($c['blocked'] ?? [] as $b) {
                    $match = ! empty($b['date']) ? $b['date'] === $date : (int) ($b['day'] ?? 0) === $dow;
                    if ($match && self::overlaps($start, $end, $b['start'] ?? null, $b['end'] ?? null)) {
                        $p->protected[$key] = $b['label'] ?? 'Protected period';
                    }
                }
            }
        }
    }

    // ---- what has to be placed ------------------------------------------------------------------------

    private function units(Problem $p, Timetable $t, $items): void
    {
        foreach ($items as $item) {
            $use = $t->isExam() ? 'exam' : ($item->activity === 'practical' ? 'practical' : 'lecture');
            $base = [
                'item' => $item->id, 'course' => $item->course_id, 'code' => $item->label, 'title' => $item->course?->title,
                'size' => (int) $item->students, 'length' => $t->isExam() ? 1 : max(1, (int) $item->duration),
                'minutes' => $t->isExam() ? (int) $item->duration : 0, 'venueType' => $item->venue_type, 'facilities' => $item->facilities ?? [],
                'staff' => array_map('intval', $item->staff ?? []), 'preferred' => $item->preferred_venue_id, 'dept' => $item->course?->department_id,
                'cohorts' => [], 'use' => $use, 'group' => $item->group_label, 'priority' => max(1, min(5, (int) ($item->priority ?? 3))),
            ];
            $n = $t->isExam() ? 1 : max(1, (int) $item->meetings);
            for ($m = 1; $m <= $n; $m++) {
                $id = $t->isExam() ? 'i' . $item->id : 'i' . $item->id . '-m' . $m;
                $p->units[$id] = ['id' => $id, 'meeting' => $m] + $base;
            }
        }
    }

    /** Students in common between items, from course registrations, plus whole programme-level cohorts from the curriculum. */
    private function conflicts(Problem $p, Timetable $t, $items): void
    {
        $courseIds = $items->pluck('course_id')->unique()->values()->all();
        $byStudent = CourseRegistration::query()
            ->join('registration_forms as f', 'f.id', '=', 'course_registrations.registration_form_id')
            ->where('f.academic_session_id', $t->academic_session_id)->where('f.semester_type_id', $t->semester_type_id)
            ->where('f.status', '!=', 'rejected')->whereIn('course_registrations.course_id', $courseIds)
            ->get(['f.student_id', 'course_registrations.course_id'])->groupBy('student_id')
            ->map(fn ($rows) => $rows->pluck('course_id')->unique()->values()->all());

        $shared = [];
        foreach ($byStudent as $courses) {
            $n = count($courses);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $shared[$courses[$i]][$courses[$j]] = ($shared[$courses[$i]][$courses[$j]] ?? 0) + 1;
                    $shared[$courses[$j]][$courses[$i]] = ($shared[$courses[$j]][$courses[$i]] ?? 0) + 1;
                }
            }
        }

        // Compulsory courses of the same programme and level always clash, even before anyone has registered.
        $order = (int) ($t->semesterType?->sort_order ?? 1);
        $cohorts = ProgrammeCourse::with('programme')->whereIn('course_id', $courseIds)->where('semester_order', $order)
            ->where('status', \App\Support\CourseType::COMPULSORY)->get();
        $cohortOf = [];
        $enrolled = StudentStatus::codesWhere('is_enrolled');
        $sizes = [];
        foreach ($cohorts as $pc) {
            $key = 'P' . $pc->programme_id . '-L' . $pc->level;
            $cohortOf[$pc->course_id][$key] = true;
            $p->cohortNames[$key] = ($pc->programme?->code ?? $pc->programme?->name ?? 'Programme') . ' ' . $pc->level . 'L';
            $sizes[$key] ??= max(1, Student::where('programme_id', $pc->programme_id)->where('current_level', $pc->level)->whereIn('status', $enrolled ?: ['active'])->count());
        }

        $list = $items->values();
        foreach ($p->units as $id => $u) {
            $p->units[$id]['cohorts'] = array_keys($cohortOf[$u['course']] ?? []);
        }
        foreach ($list as $a) {
            foreach ($list as $b) {
                if ($a->id === $b->id) {
                    continue;
                }
                if ($a->course_id === $b->course_id) {
                    // Separate groups of one course are different students; anything else of the same course is the same class.
                    $w = $a->group_label && $b->group_label && $a->group_label !== $b->group_label ? 0 : max(1, min($a->students, $b->students));
                } else {
                    $w = $shared[$a->course_id][$b->course_id] ?? 0;
                    foreach (array_intersect_key($cohortOf[$a->course_id] ?? [], $cohortOf[$b->course_id] ?? []) as $key => $_) {
                        $w = max($w, $sizes[$key]);
                    }
                }
                if ($w > 0) {
                    $p->conflicts[$a->id][$b->id] = $w;
                }
            }
        }
    }

    // ---- where ----------------------------------------------------------------------------------------

    private function venues(Problem $p, Timetable $t, array $whatIf): void
    {
        $closed = array_flip($whatIf['close_venues'] ?? []);
        $venues = Venue::with(['issues' => fn ($q) => $q->where('severity', 'blocking')->where('status', '!=', 'resolved'), 'closures' => fn ($q) => $q->whereNull('lifted_at')])
            ->where('is_active', true)->get();

        // Venues used by other published timetables of the same kind and semester (another faculty's timetable, say).
        $taken = TimetableEntry::query()->whereNotNull('venue_id')
            ->whereHas('timetable', fn ($q) => $q->where('kind', $t->kind)->where('academic_session_id', $t->academic_session_id)
                ->where('semester_type_id', $t->semester_type_id)->where('status', 'published')->where('id', '!=', $t->id)->where('id', '!=', $t->previous_id ?? 0))
            ->get(['venue_id', 'slots', 'seats']);
        $bookings = $t->isExam() && $p->slots
            ? VenueBooking::where('status', 'confirmed')->whereDate('date', '>=', min(array_filter(array_column($p->slots, 'date'))))->whereDate('date', '<=', max(array_filter(array_column($p->slots, 'date'))))->get()
            : collect();

        foreach ($venues as $v) {
            $cap = $t->isExam() ? $this->examSeats($v) : (int) ($v->teaching_capacity ?: $v->capacity);
            if (isset($whatIf['capacity'][$v->id])) {
                $cap = (int) $whatIf['capacity'][$v->id];
            }
            $row = [
                'id' => $v->id, 'code' => $v->code, 'name' => $v->name, 'cap' => $cap, 'type' => $v->type, 'facilities' => $v->facilities ?? [],
                'shared' => (bool) $v->shared_exams, 'building' => $v->building ?: $v->code, 'dept' => $v->department_id,
                'uses' => $v->uses ?: array_keys(Venue::USES), 'blocked' => [],
            ];
            $always = isset($closed[$v->id]) ? 'closed in this what-if' : $v->blockedReason();
            foreach ($p->slots as $key => $slot) {
                if ($always) {
                    $row['blocked'][$key] = $always;
                } elseif ($slot['date'] && ($why = $v->blockedReason(Carbon::parse($slot['date'])))) {
                    $row['blocked'][$key] = $why;
                }
            }
            foreach ($taken->where('venue_id', $v->id) as $e) {
                foreach ($e->slots as $key) {
                    isset($p->slots[$key]) && $row['blocked'][$key] = 'used by another published timetable';
                }
            }
            foreach ($bookings->where('venue_id', $v->id) as $b) {
                foreach ($p->slots as $key => $slot) {
                    if ($slot['date'] === $b->date->toDateString() && self::overlaps($slot['start'], $slot['end'], substr($b->starts_at, 0, 5), substr($b->ends_at, 0, 5))) {
                        $row['blocked'][$key] = 'booked: ' . $b->title;
                    }
                }
            }
            if ($cap > 0) {
                $p->venues[$v->id] = $row;
            }
        }
    }

    public function examSeats(Venue $v): int
    {
        return $v->exam_capacity > 0 || ($v->seat_rows && $v->seats_per_row) ? count($v->seatMap()) : 0;
    }

    // ---- who ------------------------------------------------------------------------------------------

    private function staff(Problem $p, Timetable $t, $items): void
    {
        $ids = $items->flatMap(fn ($i) => $i->staff ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (! $ids) {
            return;
        }
        $p->staffNames = User::whereIn('id', $ids)->pluck('name', 'id')->all();
        $dates = array_filter(array_unique(array_column($p->slots, 'date')));

        foreach (StaffUnavailability::whereIn('user_id', $ids)->get() as $u) {
            foreach ($p->slots as $key => $slot) {
                $dayMatch = $u->day && (int) $u->day === (int) $slot['day'];
                $dateMatch = $slot['date'] && $u->date_from && $slot['date'] >= $u->date_from->toDateString() && $slot['date'] <= ($u->date_to ?? $u->date_from)->toDateString();
                if (($dayMatch || $dateMatch) && self::overlaps($slot['start'], $slot['end'], $u->starts_at ? substr($u->starts_at, 0, 5) : null, $u->ends_at ? substr($u->ends_at, 0, 5) : null)) {
                    $p->staffBusy[$u->user_id][$key] = $u->reason ?: 'not available';
                }
            }
        }

        if ($dates) {
            $leave = LeaveRequest::whereIn('user_id', $ids)->where('status', 'approved')->whereNull('resumed_on')
                ->whereDate('start_date', '<=', max($dates))->whereDate('expected_resumption', '>', min($dates))->get();
            foreach ($leave as $l) {
                foreach ($p->slots as $key => $slot) {
                    if ($slot['date'] >= $l->start_date->toDateString() && $slot['date'] < $l->expected_resumption->toDateString()) {
                        $p->staffBusy[$l->user_id][$key] = 'on approved leave';
                    }
                }
            }
        }

        // Teaching already fixed in another published timetable of the same semester.
        $other = TimetableEntry::with('item')->whereHas('timetable', fn ($q) => $q->where('kind', $t->kind)->where('academic_session_id', $t->academic_session_id)
            ->where('semester_type_id', $t->semester_type_id)->where('status', 'published')->where('id', '!=', $t->id)->where('id', '!=', $t->previous_id ?? 0))->get();
        foreach ($other as $e) {
            foreach (array_intersect($ids, array_map('intval', $e->item?->staff ?? [])) as $uid) {
                foreach ($e->slots as $key) {
                    isset($p->slots[$key]) && $p->staffBusy[$uid][$key] = 'teaching in another timetable';
                }
            }
        }
    }

    // ---- helpers --------------------------------------------------------------------------------------

    public static function minutes(string $start, string $end): int
    {
        [$h1, $m1] = array_map('intval', explode(':', $start));
        [$h2, $m2] = array_map('intval', explode(':', $end));

        return ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
    }

    /** Do two time ranges overlap? A missing range means "all day". */
    public static function overlaps(string $s1, string $e1, ?string $s2, ?string $e2): bool
    {
        if (! $s2 || ! $e2) {
            return true;
        }

        return substr($s1, 0, 5) < substr($e2, 0, 5) && substr($s2, 0, 5) < substr($e1, 0, 5);
    }
}
