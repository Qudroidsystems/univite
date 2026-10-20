<?php

namespace App\Services\Senate;

use App\Data\Access\AccessScope;
use App\Data\Senate\SenateCourse;
use App\Data\Senate\SenateQuery;
use App\Data\Senate\SenateReason;
use App\Data\Senate\SenateStudent;
use App\Models\BedAllocation;
use App\Models\CarryoverWaiver;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseRegistration;
use App\Models\CourseResult;
use App\Models\DegreeClass;
use App\Models\OperationEvent;
use App\Models\Programme;
use App\Models\RegistrationForm;
use App\Models\ResultComplaint;
use App\Models\ResultSheet;
use App\Models\ResultSheetEvent;
use App\Models\ResultStage;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\SenateDecision;
use App\Models\Setting;
use App\Models\StandingWaiver;
use App\Models\Student;
use App\Models\StudentLoan;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use App\Services\Fees\FeeAccess;
use App\Services\GraduationService;
use App\Services\Library\LibraryService;
use App\Services\OperationAudit;
use App\Services\ResultService;
use App\Support\AcademicRules;
use App\Support\CourseType;
use App\Support\GradeScale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Senate's review of results at the levels it sits on (Academic Rules > Levels; by default the graduating level).
 *
 * For every student registered at such a level in the session it works out, from results still going through approval
 * as well as published ones (as the master sheet does):
 *  A. graduation eligibility: units, compulsory courses counted from the entry level, electives, outstanding courses,
 *     CGPA and class (flagging a CGPA close to a class boundary), maximum years, minimum graduation CGPA;
 *  B. result integrity: missing results, scores with no registration, scores changed after submission (who, when,
 *     why), credit loads outside the limits, sharp GPA changes, open result queries and carry-over waivers;
 *  C. clearance and standing: fees and school loans owed, library and hostel, probation or withdrawal history.
 * Each student is Eligible, Not eligible (with the reasons) or Needs Senate decision. Courses are checked for unusual
 * grade spreads, pass rates, late or returned sheets and short approval trails. Senate approves, defers or refers each.
 */
class SenateReview
{
    private const PROVISIONAL = ['submitted', 'approved', 'published'];

    public const DEFAULTS = [
        'senate.boundary_margin' => '0.05', 'senate.gpa_swing' => '1.00', 'senate.pass_high' => '95', 'senate.pass_low' => '40',
        'senate.a_share' => '50', 'senate.f_share' => '50', 'senate.avg_gap' => '15', 'senate.min_takers' => '5', 'senate.late_days' => '14',
        'senate.check_fees' => '1', 'senate.check_library' => '1', 'senate.check_hostel' => '1',
    ];

    /** @var array<int, ?float> department id => average total */
    private array $deptAverages = [];

    public function __construct(
        private GraduationService $graduation,
        private ResultService $results,
        private AcademicRules $rules,
        private ScopedAccess $access,
        private FeeAccess $fees,
        private LibraryService $library,
        private OperationAudit $audit,
    ) {
    }

    public static function setting(string $name): float
    {
        return (float) Setting::get('senate.' . $name, self::DEFAULTS['senate.' . $name]);
    }

    public static function settings(): array
    {
        return collect(self::DEFAULTS)->mapWithKeys(fn ($v, $k) => [substr($k, 7) => Setting::get($k, $v)])->all();
    }

    public function saveSettings(array $v): void
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            $name = substr($key, 7);
            if (str_starts_with($name, 'check_')) {
                Setting::put($key, ! empty($v[$name]) ? '1' : '0');
            } elseif (isset($v[$name])) {
                Setting::put($key, (string) $v[$name]);
            }
        }
    }

    public function scope(User $user): AccessScope
    {
        return $this->access->scope($user, 'View senate review');
    }

    // ---- Students ------------------------------------------------------------------------------------------

    /**
     * Students registered (approved forms) in the session at a level Senate sits on for their programme.
     *
     * @return Collection<int, array{student: Student, level: int}>
     */
    public function candidates(SenateQuery $q, AccessScope $scope): Collection
    {
        if (! $q->session) {
            return collect();
        }
        $forms = RegistrationForm::with('student.programme.department.faculty', 'student.programme.studyCategory', 'student.legacyBalance', 'student.user')
            ->where('status', 'approved')->where('academic_session_id', $q->session->id)
            ->when($q->semester, fn ($w) => $w->where('semester_type_id', $q->semester->id))
            ->whereHas('student', fn ($s) => $scope->students($s)
                ->when($q->programmeId, fn ($w) => $w->where('programme_id', $q->programmeId))
                ->when($q->departmentId, fn ($w) => $w->whereHas('programme', fn ($p) => $p->where('department_id', $q->departmentId)))
                ->when($q->facultyId, fn ($w) => $w->whereHas('programme.department', fn ($p) => $p->where('faculty_id', $q->facultyId)))
                ->when($q->search, fn ($w) => $w->where(fn ($x) => $x->where('matric_number', 'like', "%{$q->search}%")->orWhere('last_name', 'like', "%{$q->search}%")->orWhere('first_name', 'like', "%{$q->search}%"))))
            ->get();

        $senateLevels = [];

        return $forms->groupBy('student_id')->map(function ($rows) use (&$senateLevels, $q) {
            $student = $rows->first()->student;
            $level = (int) $rows->max('level');
            $p = $student->programme;
            $senateLevels[$p->id] ??= [$this->rules->senateLevels($p), $this->rules->graduatingLevel($p)];
            [$levels, $final] = $senateLevels[$p->id];
            if (! in_array($level, $levels, true) && ! ($level > $final && in_array($final, $levels, true))) {
                return null;   // spill-over students past the final level count with it
            }
            if (($q->level && $level !== $q->level) || ($q->finalOnly && $level < $final)) {
                return null;
            }

            return ['student' => $student, 'level' => $level];
        })->filter()->sortBy(fn ($r) => [$r['student']->programme->name, $r['level'], $r['student']->matric_number ?? 'zzz'])->values();
    }

    /** @return Collection<int, SenateStudent> the candidates checked, then filtered by status, finding and decision */
    public function students(SenateQuery $q, AccessScope $scope): Collection
    {
        $rows = $this->candidates($q, $scope)->map(fn ($c) => $this->student($c['student'], $c['level'], $q));

        return $rows->filter(fn (SenateStudent $r) => (! $q->status || $r->status() === $q->status)
            && (! $q->flag || $r->has($q->flag))
            && (! $q->decision || ($q->decision === 'none' ? ! $r->decision : $r->decision?->decision === $q->decision)))->values();
    }

    public function student(Student $student, int $level, SenateQuery $q): SenateStudent
    {
        $programme = $student->programme;
        $rules = $this->rules->forProgramme($programme);
        $final = $level >= $this->rules->graduatingLevel($programme);
        $session = $q->session;

        $all = CourseResult::with('course', 'session', 'semesterType')->where('student_id', $student->id)->whereIn('status', self::PROVISIONAL)->get();
        $t = $this->results->transcriptFrom($student, $all);
        $inSession = $all->filter(fn ($r) => $r->academic_session_id === $session->id && (! $q->semester || $r->semester_type_id === $q->semester->id));
        $gpa = (float) GradeScale::gpa($inSession);
        $earlier = $all->filter(fn ($r) => $r->session && $r->session->name < $session->name);
        $lastEarlier = $earlier->groupBy('academic_session_id')->sortBy(fn ($g) => $g->first()->session->name)->last();
        $previousGpa = $lastEarlier ? (float) GradeScale::gpa($lastEarlier) : null;
        $reasons = [];
        $class = $t->class;
        $earned = $t->earned;

        // ---- A. graduation eligibility (or progression for a level below the final one) ----
        $forms = RegistrationForm::with('courses')->where('student_id', $student->id)->where('status', 'approved')->where('academic_session_id', $session->id)
            ->when($q->semester, fn ($w) => $w->where('semester_type_id', $q->semester->id))->get();
        $registered = $forms->flatMap(fn ($f) => $f->courses->map(fn ($c) => ['course_id' => $c->course_id, 'semester_type_id' => $f->semester_type_id, 'code' => null]));
        if ($final) {
            $check = $this->graduation->check($student, true);
            foreach ($check->problems as $i => $text) {
                $code = $check->codes[$i] ?? 'outstanding';
                $reasons[] = new SenateReason($code, $text, blocks: $code !== 'max_years');
            }
            $class = $check->degreeClass;
            $earned = $check->unitsPassed;
            if ($b = $this->boundary($check->cgpa)) {
                $reasons[] = new SenateReason('boundary', $b);
            }
        } else {
            $have = $inSession->map(fn ($r) => $r->course_id . '-' . $r->semester_type_id)->flip();
            $missing = $registered->reject(fn ($c) => $have->has($c['course_id'] . '-' . $c['semester_type_id']))->pluck('course_id')->unique();
            if ($missing->isNotEmpty()) {
                $reasons[] = new SenateReason('missing', 'No result yet for: ' . Course::whereIn('id', $missing)->orderBy('code')->pluck('code')->implode(', ') . '.', blocks: true);
            }
            if ($t->standing === 'Advised to withdraw') {
                $reasons[] = new SenateReason('withdraw', 'CGPA ' . number_format($t->cgpa, 2) . ' is below the withdrawal CGPA: advised to withdraw.', blocks: true);
            } elseif ($t->standing === 'On probation') {
                $reasons[] = new SenateReason('probation', 'CGPA ' . number_format($t->cgpa, 2) . ': on probation.');
            }
            $failed = $this->failedCodes($student, $all, $rules);
            if ($failed) {
                $reasons[] = new SenateReason('outstanding', 'Carries over: ' . implode(', ', $failed) . '.', info: true);
            }
        }

        // ---- B. result integrity ----
        $regKeys = $registered->map(fn ($c) => $c['course_id'] . '-' . $c['semester_type_id'])->flip();
        $nr = $inSession->reject(fn ($r) => $regKeys->has($r->course_id . '-' . $r->semester_type_id));
        if ($nr->isNotEmpty()) {
            $reasons[] = new SenateReason('unregistered', 'Has a score but no approved registration for: ' . $nr->map(fn ($r) => $r->course?->code)->sort()->implode(', ') . '.');
        }
        foreach ($this->changes($student, $inSession) as $text) {
            $reasons[] = new SenateReason('amended', $text);
        }
        foreach ($forms as $f) {
            $r = $this->rules->forProgramme($programme, (int) $f->level, $f->semester_type_id);
            $units = (int) $f->courses->sum('credit_units');
            $counted = $r['carryover_exempt'] ? (int) $f->courses->where('is_carryover', false)->sum('credit_units') : $units;
            $sem = SemesterType::find($f->semester_type_id)?->name;
            if ($r['min_units'] !== null && $units < $r['min_units']) {
                $reasons[] = new SenateReason('credit_load', "Registered {$units} units in the {$sem}, below the minimum of {$r['min_units']}.");
            } elseif ($r['max_units'] !== null && $counted > $r['max_units']) {
                $reasons[] = new SenateReason('credit_load', "Registered {$counted} units in the {$sem}, above the maximum of {$r['max_units']}.");
            }
        }
        if ($previousGpa !== null && $inSession->isNotEmpty() && abs($gpa - $previousGpa) >= self::setting('gpa_swing')) {
            $reasons[] = new SenateReason('gpa_swing', 'GPA ' . ($gpa > $previousGpa ? 'rose' : 'fell') . ' from ' . number_format($previousGpa, 2) . ' to ' . number_format($gpa, 2) . ' this session.');
        }
        $open = ResultComplaint::with('result.course')->where('student_id', $student->id)->where('status', 'open')->get();
        if ($open->isNotEmpty()) {
            $reasons[] = new SenateReason('complaint', 'Result query waiting for an answer: ' . $open->map(fn ($c) => $c->result?->course?->code)->filter()->implode(', ') . '.');
        }
        $waived = CarryoverWaiver::active()->with('course')->where('student_id', $student->id)->get();
        if ($waived->isNotEmpty()) {
            $reasons[] = new SenateReason('waiver', 'Carry-over waived: ' . $waived->map(fn ($w) => $w->course?->code . ($w->reason ? " ({$w->reason})" : ''))->implode(', ') . '.');
        }

        // ---- C. clearance and standing ----
        if (self::setting('check_fees')) {
            $s = $this->fees->standing($student, $session);
            if ($s->billed && $s->outstanding() > 0.009) {
                $reasons[] = new SenateReason('fees', 'Owes ' . number_format($s->outstanding(), 2) . " of the {$session->name} fees (" . rtrim(rtrim(number_format($s->percent, 1), '0'), '.') . '% paid).');
            }
            if ($s->arrears > 0.009) {
                $reasons[] = new SenateReason('fees', 'Owes ' . number_format($s->arrears, 2) . ' from earlier sessions.');
            }
            $loan = StudentLoan::where('student_id', $student->id)->where('status', 'disbursed')->get();
            if ($loan->isNotEmpty() && ($bal = $loan->sum(fn ($l) => $l->balance)) > 0.009) {
                $reasons[] = new SenateReason('loan', 'School loan still owed: ' . number_format($bal, 2) . ($loan->sum(fn ($l) => $l->overdue()) > 0 ? ' (repayment overdue)' : '') . '.');
            }
        }
        if ($final && self::setting('check_library') && $student->user) {
            $lib = $this->library->standing($student->user);
            if (! $lib->isClear()) {
                $reasons[] = new SenateReason('library', 'Library not cleared: ' . collect([$lib->onLoan ? "{$lib->onLoan} book(s) out" : null, $lib->finesOwed > 0 ? 'fines of ' . number_format($lib->finesOwed, 2) : null])->filter()->implode(', ') . '.');
            }
        }
        if ($final && self::setting('check_hostel') && BedAllocation::where('student_id', $student->id)->where('status', 'checked_in')->exists()) {
            $reasons[] = new SenateReason('hostel', 'Still checked in to a hostel (not checked out).');
        }
        if ($history = $this->standingHistory($student, $all, $session->name)) {
            $reasons[] = new SenateReason('standing_history', $history, info: true);
        }
        $waivers = StandingWaiver::with('session')->where('student_id', $student->id)->get();
        if ($waivers->isNotEmpty()) {
            $reasons[] = new SenateReason('standing_waiver', 'Allowed to register despite academic standing in ' . $waivers->map(fn ($w) => $w->session?->name)->filter()->implode(', ') . '.');
        }

        $decision = SenateDecision::with('decider')->where('academic_session_id', $session->id)->where('subject_type', 'student')->where('subject_id', $student->id)->latest('id')->first();

        return new SenateStudent($student, $level, $final, $final ? ($check->cgpa ?? $t->cgpa) : $t->cgpa, $gpa, $class, (int) $earned, $rules['graduation_units'],
            (int) $inSession->sum('credit_units'), $reasons, $decision, $previousGpa);
    }

    /** "CGPA 3.47 is 0.03 below Second Class Upper (3.50)" when within the margin of the nearest class boundary. */
    public function boundary(float $cgpa): ?string
    {
        $margin = self::setting('boundary_margin');
        $best = null;
        foreach (DegreeClass::orderBy('min_cgpa')->get() as $c) {
            $gap = round($cgpa - (float) $c->min_cgpa, 2);
            if (abs($gap) <= $margin + 0.0001 && ($best === null || abs($gap) < abs($best[0]))) {
                $best = [$gap, $c];
            }
        }
        if (! $best) {
            return null;
        }
        [$gap, $c] = $best;

        return $gap < 0
            ? 'CGPA ' . number_format($cgpa, 2) . ' is ' . number_format(-$gap, 2) . " below {$c->name} (" . number_format($c->min_cgpa, 2) . ').'
            : 'CGPA ' . number_format($cgpa, 2) . ' is only ' . number_format($gap, 2) . " above the {$c->name} boundary (" . number_format($c->min_cgpa, 2) . ').';
    }

    private function failedCodes(Student $student, Collection $all, array $rules): array
    {
        $types = \App\Models\ProgrammeCourse::where('programme_id', $student->programme_id)->pluck('status', 'course_id');
        $passed = $all->where('is_pass', true)->pluck('course_id')->flip();
        $waived = CarryoverWaiver::courseIdsFor($student->id)->flip();

        return $all->where('is_pass', false)->reject(fn ($r) => $passed->has($r->course_id) || $waived->has($r->course_id))
            ->filter(fn ($r) => CourseType::mustRetake($types->get($r->course_id) ?? CourseType::COMPULSORY, $rules))
            ->map(fn ($r) => $r->course?->code)->filter()->unique()->sort()->values()->all();
    }

    /** Scores changed after the sheet left the lecturer: published scores amended, and changes made during approval. @return string[] */
    private function changes(Student $student, Collection $inSession): array
    {
        $out = [];
        if ($inSession->isEmpty()) {
            return $out;
        }
        $byId = $inSession->keyBy('id');
        OperationEvent::with('user', 'batch')->whereIn('subject_type', [class_basename(CourseResult::class), CourseResult::class])->whereIn('subject_id', $byId->keys())->where('action', 'score_amended')->get()
            ->each(function ($e) use (&$out, $byId) {
                $r = $byId->get($e->subject_id);
                $out[] = "{$r?->course?->code} amended after publication by " . ($e->user?->name ?? 'someone') . ' on ' . $e->created_at?->format('j M Y')
                    . ': ' . ($e->before['grade'] ?? '?') . ' to ' . ($e->after['grade'] ?? '?') . ($e->batch?->reason ? " ({$e->batch->reason})" : ($e->note ? " ({$e->note})" : '')) . '.';
            });
        $matric = $student->matric_number;
        if ($matric) {
            ResultSheetEvent::with('user')->whereIn('result_sheet_id', $inSession->pluck('result_sheet_id')->filter()->unique())->where('action', 'corrected')
                ->where('note', 'like', '%' . $matric . '%')->get()->each(function ($e) use (&$out, $inSession) {
                    $code = $inSession->firstWhere('result_sheet_id', $e->result_sheet_id)?->course?->code;
                    $out[] = "{$code} score changed during approval" . ($e->stage ? " at {$e->stage}" : '') . ' by ' . ($e->user?->name ?? 'someone') . ' on ' . $e->created_at?->format('j M Y') . '.';
                });
        }

        return $out;
    }

    /** "On probation after 2024/2025; advised to withdraw after 2025/2026" from the CGPA at the end of each earlier session. */
    private function standingHistory(Student $student, Collection $all, string $before): ?string
    {
        $notes = [];
        $sessions = $all->filter(fn ($r) => $r->session && $r->session->name < $before)->groupBy(fn ($r) => $r->session->name)->keys()->sort()->values();
        foreach ($sessions as $name) {
            $t = $this->results->transcriptFrom($student, $all->filter(fn ($r) => $r->session->name <= $name)->values());
            if ($t->standing) {
                $notes[] = strtolower($t->standing) . " after {$name}";
            }
        }

        return $notes ? ucfirst(implode('; ', $notes)) . '.' : null;
    }

    // ---- Courses ---------------------------------------------------------------------------------------------

    /** @return Collection<int, SenateCourse> the courses Senate's students took in the session, checked */
    public function courses(SenateQuery $q, AccessScope $scope): Collection
    {
        if (! $q->session) {
            return collect();
        }
        $ids = $this->candidates(new SenateQuery($q->session, $q->semester, $q->facultyId, $q->departmentId, $q->programmeId, $q->level, null, null, null, null, null, $q->finalOnly), $scope)
            ->map(fn ($c) => $c['student']->id);
        $regs = CourseRegistration::query()->join('registration_forms as f', 'f.id', '=', 'course_registrations.registration_form_id')
            ->whereIn('course_registrations.student_id', $ids)->where('f.status', 'approved')->where('f.academic_session_id', $q->session->id)
            ->when($q->semester, fn ($w) => $w->where('f.semester_type_id', $q->semester->id))
            ->select('course_registrations.course_id', 'f.semester_type_id')->distinct()->get();
        $courses = Course::with('department.faculty')->whereIn('id', $regs->pluck('course_id')->unique())
            ->when($q->course, fn ($w) => $w->where(fn ($x) => $x->where('code', 'like', "%{$q->course}%")->orWhere('title', 'like', "%{$q->course}%")))->get()->keyBy('id');
        $types = SemesterType::pluck('name', 'id');
        $chain = ResultStage::chain();

        $rows = $regs->filter(fn ($r) => $courses->has($r->course_id))
            ->map(fn ($r) => $this->course($courses[$r->course_id], $q, (int) $r->semester_type_id, $types[$r->semester_type_id] ?? null, $chain))
            ->sortBy(fn (SenateCourse $c) => [$c->course->code, $c->semester])->values();

        return $rows->filter(fn (SenateCourse $c) => (! $q->status || $q->status === 'not_eligible' ? true : $c->status() === $q->status)
            && (! $q->flag || $c->has($q->flag))
            && (! $q->decision || ($q->decision === 'none' ? ! $c->decision : $c->decision?->decision === $q->decision)))->values();
    }

    public function course(Course $course, SenateQuery $q, int $semesterTypeId, ?string $semesterName, Collection $chain): SenateCourse
    {
        $session = $q->session;
        $results = CourseResult::where('course_id', $course->id)->where('academic_session_id', $session->id)->where('semester_type_id', $semesterTypeId)
            ->whereIn('status', self::PROVISIONAL)->get();
        $registered = CourseRegistration::where('course_id', $course->id)->whereHas('form', fn ($f) => $f->where('status', 'approved')
            ->where('academic_session_id', $session->id)->where('semester_type_id', $semesterTypeId))->count();
        $graded = $results->filter(fn ($r) => $r->total !== null);
        $passed = $graded->where('is_pass', true)->count();
        $failed = $graded->where('is_pass', false)->count();
        $avg = $graded->isEmpty() ? null : round((float) $graded->avg('total'), 1);
        $deptAvg = $this->departmentAverage($course->department_id, $session->id);
        $grades = GradeScale::bands()->sortByDesc('min_score')->mapWithKeys(fn ($b) => [$b->letter => 0])->all();
        foreach ($graded->countBy('grade') as $g => $n) {
            $grades[$g] = ($grades[$g] ?? 0) + $n;
        }
        $sheet = ResultSheet::with('stage')->where('course_id', $course->id)->where('academic_session_id', $session->id)->where('semester_type_id', $semesterTypeId)->first();
        $trail = $sheet ? ResultSheetEvent::with('user')->where('result_sheet_id', $sheet->id)->orderBy('id')->get() : collect();
        $lecturers = CourseAssignment::with('user')->where('course_id', $course->id)->where('academic_session_id', $session->id)->where('semester_type_id', $semesterTypeId)
            ->get()->map(fn ($a) => $a->user?->name)->filter()->unique()->values()->all();

        $reasons = [];
        $n = $graded->count();
        if ($n >= (int) self::setting('min_takers')) {
            $rate = $passed / $n * 100;
            $top = array_key_first($grades);
            $bottom = array_key_last($grades);
            if ($rate >= self::setting('pass_high')) {
                $reasons[] = new SenateReason('pass_high', 'Pass rate ' . round($rate, 1) . '%.');
            } elseif ($rate < self::setting('pass_low')) {
                $reasons[] = new SenateReason('pass_low', 'Pass rate only ' . round($rate, 1) . '%.');
            }
            if ($top && ($grades[$top] / $n * 100) >= self::setting('a_share')) {
                $reasons[] = new SenateReason('many_a', round($grades[$top] / $n * 100) . "% of students got {$top}.");
            }
            if ($bottom && $bottom !== $top && ($grades[$bottom] / $n * 100) >= self::setting('f_share')) {
                $reasons[] = new SenateReason('many_f', round($grades[$bottom] / $n * 100) . "% of students got {$bottom}.");
            }
            if ($avg !== null && $deptAvg !== null && abs($avg - $deptAvg) >= self::setting('avg_gap')) {
                $reasons[] = new SenateReason('avg_gap', "Average {$avg} against the department's {$deptAvg} this session.");
            }
        }
        if ($registered > $results->count()) {
            $reasons[] = new SenateReason('course_missing', ($registered - $results->count()) . ' registered ' . ($registered - $results->count() === 1 ? 'student has' : 'students have') . ' no result.');
        }
        if (! $sheet || in_array($sheet->status, ['draft', 'submitted'], true)) {
            $reasons[] = new SenateReason('not_approved', ! $sheet ? 'No score sheet opened yet.' : ($sheet->status === 'draft' ? 'Still with the lecturer (draft).' : 'Waiting at ' . ($sheet->stage?->name ?? 'approval') . '.'));
        }
        if ($sheet?->submitted_at) {
            $end = Semester::where('academic_session_id', $session->id)->where('sort_order', SemesterType::whereKey($semesterTypeId)->value('sort_order'))->value('end_date');
            if ($end && $sheet->submitted_at->gt(\Illuminate\Support\Carbon::parse($end)->endOfDay()->addDays((int) self::setting('late_days')))) {
                $reasons[] = new SenateReason('late', 'Submitted ' . $sheet->submitted_at->format('j M Y') . ', ' . (int) \Illuminate\Support\Carbon::parse($end)->diffInDays($sheet->submitted_at) . ' days after the semester ended.');
            }
        }
        $returned = $trail->where('action', 'returned');
        if ($returned->isNotEmpty()) {
            $reasons[] = new SenateReason('returned', 'Returned to the lecturer ' . $returned->count() . ' ' . ($returned->count() === 1 ? 'time' : 'times') . ': ' . $returned->pluck('note')->filter()->implode('; ') . '.');
        }
        $changed = $trail->whereIn('action', ['corrected', 'amended']);
        if ($changed->isNotEmpty()) {
            $reasons[] = new SenateReason('corrected', 'Scores changed ' . $changed->count() . ' ' . ($changed->count() === 1 ? 'time' : 'times') . ' after submission (' . $changed->map(fn ($e) => ($e->user?->name ?? '?') . ', ' . $e->created_at?->format('j M'))->implode('; ') . ').');
        }
        if ($sheet && in_array($sheet->status, ['approved', 'published'], true) && $chain->isNotEmpty()) {
            $since = $trail->where('action', 'submitted')->max('id');
            $signed = $trail->where('action', 'approved')->filter(fn ($e) => ! $since || $e->id > $since)->pluck('stage')->unique()->count();
            if ($signed < $chain->count()) {
                $reasons[] = new SenateReason('short_chain', "Signed at {$signed} of the {$chain->count()} approval steps.");
            }
        }

        $decision = SenateDecision::with('decider')->where('academic_session_id', $session->id)->where('subject_type', 'course')->where('subject_id', $course->id)
            ->where('semester_type_id', $semesterTypeId)->latest('id')->first();

        return new SenateCourse($course, $semesterName, $registered, $results->count(), $passed, $failed, $avg, $deptAvg, $grades, $lecturers,
            $sheet?->status, $sheet?->stage?->name, $sheet?->submitted_at?->format('j M Y'), $trail, $reasons, $decision, $sheet?->id);
    }

    private function departmentAverage(?int $departmentId, int $sessionId): ?float
    {
        if (! $departmentId) {
            return null;
        }

        return $this->deptAverages[$departmentId . ':' . $sessionId] ??= (fn ($v) => $v === null ? null : round((float) $v, 1))(
            CourseResult::whereIn('status', self::PROVISIONAL)->where('academic_session_id', $sessionId)->whereNotNull('total')
                ->whereHas('course', fn ($c) => $c->where('department_id', $departmentId))->avg('total'));
    }

    // ---- Summaries -------------------------------------------------------------------------------------------

    /**
     * Per faculty, department and programme: candidates, eligible, not eligible, needs decision, class distribution,
     * the commonest reasons and the courses' pass rate.
     *
     * @param  Collection<int, SenateStudent>  $students
     * @param  Collection<int, SenateCourse>  $courses
     */
    public function summary(Collection $students, Collection $courses): array
    {
        $line = function (Collection $rows, Collection $courseRows) {
            $reasons = $rows->flatMap(fn (SenateStudent $s) => collect($s->reasons)->reject(fn ($r) => $r->info)->map(fn ($r) => $r->label())->unique())->countBy()->sortDesc()->take(6)->all();
            $graded = $courseRows->sum(fn (SenateCourse $c) => $c->withResult);

            return [
                'candidates' => $rows->count(), 'eligible' => $rows->filter(fn ($s) => $s->status() === 'eligible')->count(),
                'not_eligible' => $rows->filter(fn ($s) => $s->status() === 'not_eligible')->count(), 'needs_decision' => $rows->filter(fn ($s) => $s->status() === 'needs_decision')->count(),
                'approved' => $rows->filter(fn ($s) => $s->decision?->decision === 'approved')->count(),
                'classes' => $rows->filter(fn ($s) => $s->final)->countBy(fn ($s) => $s->class ?? 'No class')->all(),
                'reasons' => $reasons, 'pass_rate' => $graded ? round($courseRows->sum(fn ($c) => $c->passed) / $graded * 100, 1) : null,
                'average_cgpa' => $rows->isEmpty() ? null : round((float) $rows->avg(fn ($s) => $s->cgpa), 2),
            ];
        };
        $out = [];
        foreach ($students->groupBy(fn ($s) => $s->student->programme->department->faculty->name ?? '—')->sortKeys() as $faculty => $fRows) {
            $fCourses = $courses->filter(fn ($c) => ($c->course->department?->faculty?->name ?? '—') === $faculty);
            $depts = [];
            foreach ($fRows->groupBy(fn ($s) => $s->student->programme->department->name)->sortKeys() as $dept => $dRows) {
                $dCourses = $courses->filter(fn ($c) => $c->course->department?->name === $dept);
                $progs = [];
                foreach ($dRows->groupBy(fn ($s) => $s->student->programme->name)->sortKeys() as $prog => $pRows) {
                    $progs[$prog] = $line($pRows, collect());
                }
                $depts[$dept] = ['total' => $line($dRows, $dCourses), 'programmes' => $progs];
            }
            $out[$faculty] = ['total' => $line($fRows, $fCourses), 'departments' => $depts];
        }

        return ['faculties' => $out, 'all' => $line($students, $courses)];
    }

    // ---- Decisions ---------------------------------------------------------------------------------------------

    public function decide(string $type, int $id, SenateQuery $q, string $decision, ?string $note, User $by, ?int $semesterTypeId = null): SenateDecision
    {
        if (! in_array($decision, array_keys(SenateDecision::DECISIONS), true)) {
            throw new RuntimeException('Choose approve, defer or refer.');
        }
        if ($decision !== 'approved' && blank($note)) {
            throw new RuntimeException('Give Senate\'s reason for deferring or referring.');
        }
        $scope = $this->scope($by);
        if ($type === 'student') {
            $student = Student::with('programme.department.faculty', 'user', 'legacyBalance')->findOrFail($id);
            abort_unless($scope->coversStudent($student), 403);
            $level = (int) RegistrationForm::where('student_id', $id)->where('academic_session_id', $q->session->id)->where('status', 'approved')->max('level') ?: (int) $student->current_level;
            $row = $this->student($student, $level, $q);
            $seen = $row->status();
            $reasons = array_map(fn ($r) => $r->toArray(), $row->reasons);
            $programmeId = $student->programme_id;
        } else {
            $course = Course::with('department.faculty')->findOrFail($id);
            abort_unless($scope->coversDepartment($course->department_id) || $scope->all, 403);
            $row = $this->course($course, $q, (int) $semesterTypeId, SemesterType::find($semesterTypeId)?->name, ResultStage::chain());
            $seen = $row->status();
            $reasons = array_map(fn ($r) => $r->toArray(), $row->reasons);
            $programmeId = null;
            $level = $course->level;
        }

        $d = SenateDecision::create([
            'academic_session_id' => $q->session->id, 'semester_type_id' => $type === 'course' ? $semesterTypeId : $q->semester?->id, 'subject_type' => $type, 'subject_id' => $id,
            'programme_id' => $programmeId, 'level' => $level, 'decision' => $decision, 'note' => $note, 'status_seen' => $seen, 'reasons' => $reasons, 'decided_by' => $by->id,
        ]);
        $this->audit->note('senate_decision', 'results', $by, 'senate_' . $decision, $type === 'student' ? $student : $course, ['status' => $seen], ['decision' => $decision], $note, $q->session->id);

        return $d;
    }

    /** Approve every eligible student in the view who has no approval yet. @return int how many */
    public function approveEligible(SenateQuery $q, User $by, ?string $note = null): int
    {
        $rows = $this->students($q, $this->scope($by))->filter(fn (SenateStudent $s) => $s->status() === 'eligible' && $s->decision?->decision !== 'approved');
        DB::transaction(function () use ($rows, $q, $by, $note) {
            foreach ($rows as $s) {
                SenateDecision::create([
                    'academic_session_id' => $q->session->id, 'semester_type_id' => $q->semester?->id, 'subject_type' => 'student', 'subject_id' => $s->student->id,
                    'programme_id' => $s->student->programme_id, 'level' => $s->level, 'decision' => 'approved', 'note' => $note ?: 'Approved with the eligible list.',
                    'status_seen' => 'eligible', 'reasons' => array_map(fn ($r) => $r->toArray(), $s->reasons), 'decided_by' => $by->id,
                ]);
            }
        });
        if ($rows->isNotEmpty()) {
            $this->audit->note('senate_decision', 'results', $by, 'senate_bulk_approved', null, null, ['students' => $rows->count(), 'view' => $q->params()], $note, $q->session->id);
        }

        return $rows->count();
    }
}
