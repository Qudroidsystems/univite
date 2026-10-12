<?php

namespace App\Services;

use App\Data\Results\BroughtForward;
use App\Data\Results\Score;
use App\Data\Results\ScoreImport;
use App\Data\Results\Transcript;
use App\Data\Results\TranscriptTerm;
use App\Models\AssessmentScheme;
use App\Models\CourseResult;
use App\Models\RegistrationForm;
use App\Models\ResultSheet;
use App\Models\ResultSheetEvent;
use App\Models\ResultStage;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Support\GradeScale;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Score entry, upload, the approval chain and publishing for course score sheets. */
class ResultService
{
    public function __construct(
        private OperationAudit $audit,
        private DocumentMailer $mailer,
        private \App\Support\AcademicRules $rules,
        private \App\Support\SpreadsheetReader $reader,
        private Messenger $messenger,
        private AssessmentService $assessments,
    ) {
    }

    public static function caMax(): float
    {
        return (float) Setting::get('results.ca_max', '30');
    }

    public static function examMax(): float
    {
        return (float) Setting::get('results.exam_max', '70');
    }

    /**
     * The most a CA and an exam can be on this sheet: from its assessment scheme, or the Result Settings split.
     *
     * @return array{0: float, 1: float}
     */
    public static function limits(ResultSheet $sheet): array
    {
        $scheme = $sheet->scheme;
        if ($scheme) {
            $scheme->loadMissing('items');

            return [$scheme->caMax(), $scheme->examMax()];
        }

        return [self::caMax(), self::examMax()];
    }

    /** The sheet for a course in a session/semester, with a row for every student registered (approved forms only). */
    public function sheet(int $courseId, int $sessionId, int $semesterTypeId): ResultSheet
    {
        $sheet = ResultSheet::firstOrCreate(['course_id' => $courseId, 'academic_session_id' => $sessionId, 'semester_type_id' => $semesterTypeId]);
        if ($sheet->isEditable()) {
            $this->syncRows($sheet);
        }

        return $sheet;
    }

    public function syncRows(ResultSheet $sheet): void
    {
        $this->assessments->attach($sheet);
        $registered = RegistrationForm::where('status', 'approved')->where('academic_session_id', $sheet->academic_session_id)
            ->where('semester_type_id', $sheet->semester_type_id)
            ->whereHas('courses', fn ($q) => $q->where('course_id', $sheet->course_id))
            ->with(['courses' => fn ($q) => $q->where('course_id', $sheet->course_id)])->get();

        foreach ($registered as $form) {
            CourseResult::firstOrCreate(
                ['student_id' => $form->student_id, 'course_id' => $sheet->course_id, 'academic_session_id' => $sheet->academic_session_id, 'semester_type_id' => $sheet->semester_type_id],
                ['result_sheet_id' => $sheet->id, 'credit_units' => $form->courses->first()->credit_units, 'status' => 'draft']
            );
        }
    }

    public function log(ResultSheet $sheet, ?User $user, string $action, ?string $stage = null, ?string $note = null): void
    {
        ResultSheetEvent::create(['result_sheet_id' => $sheet->id, 'user_id' => $user?->id, 'action' => $action, 'stage' => $stage, 'note' => $note, 'created_at' => now()]);
    }

    public function canEnter(User $user, ResultSheet $sheet): bool
    {
        return $user->can('Enter results for any course') || $user->courseAssignments()
            ->where('course_id', $sheet->course_id)->where('academic_session_id', $sheet->academic_session_id)->where('semester_type_id', $sheet->semester_type_id)->exists();
    }

    /**
     * Set one student's scores. Blank clears. Throws RuntimeException with a readable message when a score is out of range.
     * On a sheet with an assessment scheme the marks go on its items ($items when given, otherwise the CA and exam are
     * spread over the items) and the CA and exam become what the items add up to.
     */
    private function apply(CourseResult $row, $ca, $exam, ?AssessmentScheme $scheme = null, ?array $items = null, ?User $user = null): void
    {
        if ($scheme) {
            [$ca, $exam] = $items !== null
                ? $this->assessments->applyItems($row, $scheme, $items, $user)
                : $this->assessments->distribute($row, $scheme, $ca, $exam, $user);
        } else {
            $ca = ($ca === null || $ca === '') ? null : $ca;
            $exam = ($exam === null || $exam === '') ? null : $exam;
            foreach ([[$ca, self::caMax(), 'CA'], [$exam, self::examMax(), 'Exam']] as [$v, $max, $label]) {
                if ($v !== null && (! is_numeric($v) || $v < 0 || $v > $max)) {
                    throw new RuntimeException("{$label} score must be a number from 0 to {$max}.");
                }
            }
        }

        $total = ($ca !== null && $exam !== null) ? round((float) $ca + (float) $exam, 2) : null;
        $g = GradeScale::grade($total);
        $row->update([
            'ca' => $ca, 'exam' => $exam, 'total' => $total,
            'grade' => $g['grade'] ?? null, 'points' => $g['points'] ?? null, 'is_pass' => $g['is_pass'] ?? null,
        ]);
    }

    /**
     * @param  array<int, Score>  $scores  keyed by student id
     * @return string[] problems; valid rows are still saved
     */
    public function saveScores(ResultSheet $sheet, array $scores, ?User $user = null, string $action = 'saved'): array
    {
        if (! $sheet->isEditable()) {
            throw new RuntimeException('This sheet has been submitted and can no longer be changed.');
        }
        $rows = $sheet->results()->with('student')->get()->keyBy('student_id');
        $scheme = $sheet->scheme?->loadMissing('items');
        $problems = [];

        DB::transaction(function () use ($scores, $rows, $scheme, $user, &$problems) {
            foreach ($scores as $studentId => $s) {
                $row = $rows->get((int) $studentId);
                if (! $row) {
                    continue;
                }
                try {
                    DB::transaction(fn () => $this->apply($row, $s->ca, $s->exam, $scheme, $s->items, $user));
                } catch (RuntimeException $e) {
                    $problems[] = $row->student->matric_number . ': ' . $e->getMessage();
                }
            }
        });
        $this->log($sheet, $user, $action);

        return $problems;
    }

    /** A column heading reduced to letters and digits, without a "(out of …)" note: "Test 1 (10)" => "test1". */
    public static function headKey($h): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', preg_replace('/\(.*?\)/', '', (string) $h)));
    }

    /**
     * Read a CSV or Excel upload and save the scores. The first row is the header: Matric Number, then CA and Exam, or
     * on a sheet with an assessment scheme one column per assessment (its code or name). Columns left out keep their marks.
     */
    public function import(ResultSheet $sheet, UploadedFile $file, ?User $user = null): ScoreImport
    {
        $table = $this->readTable($file);
        $head = array_map(fn ($h) => self::headKey($h), array_shift($table) ?? []);
        $col = fn (array $names) => collect($names)->map(fn ($n) => array_search($n, $head, true))->first(fn ($i) => $i !== false);
        $m = $col(['matricnumber', 'matricno', 'matric', 'regno', 'regnumber']);
        $ca = $col(['ca', 'continuousassessment', 'testscore', 'catest']);
        $ex = $col(['exam', 'examscore', 'examination']);

        $itemCols = [];
        if ($scheme = $sheet->scheme?->loadMissing('items')) {
            foreach ($scheme->items as $item) {
                $i = $col(array_unique([self::headKey($item->code), self::headKey($item->name)]));
                if ($i !== null) {
                    $itemCols[$item->id] = $i;
                }
            }
        }
        if ($itemCols) {
            $ca = $ex = null;   // per-item columns win over CA / Exam totals
        }
        if ($m === null || (! $itemCols && $ca === null && $ex === null)) {
            throw new RuntimeException($scheme
                ? 'The first row must name the columns: Matric Number and the assessments (' . $scheme->items->pluck('code')->implode(', ') . ').'
                : 'The first row must name the columns: Matric Number, CA and Exam.');
        }

        $byMatric = $sheet->results()->with('student')->get()->keyBy(fn ($r) => strtoupper(trim((string) $r->student->matric_number)));
        $scores = [];
        $problems = [];
        foreach ($table as $n => $line) {
            $matric = strtoupper(trim((string) ($line[$m] ?? '')));
            if ($matric === '') {
                continue;
            }
            $row = $byMatric->get($matric);
            if (! $row) {
                $problems[] = 'Row ' . ($n + 2) . ": {$matric} is not registered for this course.";
                continue;
            }
            $scores[$row->student_id] = $itemCols
                ? new Score(items: array_map(fn ($i) => $line[$i] ?? null, $itemCols))
                : new Score(
                    $ca !== null ? ($line[$ca] ?? null) : $row->ca,
                    $ex !== null ? ($line[$ex] ?? null) : $row->exam,
                );
        }

        return new ScoreImport(count($scores), array_merge($problems, $this->saveScores($sheet, $scores, $user, 'imported')));
    }

    private function readTable(UploadedFile $file): array
    {
        return $this->reader->read($file->getRealPath(), $file->getClientOriginalExtension());
    }

    /** Rows that still lack a CA or exam score (on a scheme sheet: a mark for one of the assessments). */
    public function incomplete(ResultSheet $sheet): int
    {
        return $sheet->results()->where(fn ($q) => $q->whereNull('ca')->orWhereNull('exam'))->count();
    }

    public function submit(ResultSheet $sheet, User $user): ResultSheet
    {
        if (! $sheet->isEditable()) {
            throw new RuntimeException('This sheet has already been submitted.');
        }
        if ($sheet->results()->doesntExist()) {
            throw new RuntimeException('No student has registered for this course yet.');
        }
        if ($n = $this->incomplete($sheet)) {
            throw new RuntimeException($sheet->assessment_scheme_id
                ? "{$n} student(s) still have no mark for one or more assessments. Enter every mark (use 0 for an absent student) before submitting."
                : "{$n} student(s) still have no CA or exam score. Enter every score (use 0 for an absent student) before submitting.");
        }

        $first = ResultStage::chain()->first();
        $sheet->update(['status' => $first ? 'submitted' : 'approved', 'stage_id' => $first?->id, 'submitted_by' => $user->id, 'submitted_at' => now()]);
        $this->mirror($sheet);
        $this->log($sheet, $user, 'submitted', $first?->name);

        return $sheet;
    }

    public function canApprove(User $user, ResultSheet $sheet): bool
    {
        if ($sheet->status !== 'submitted' || ! $sheet->stage) {
            return false;
        }

        return $user->can('Approve results for any stage')
            || ($user->can('Approve results') && (! $sheet->stage->office_role || $user->hasRole($sheet->stage->office_role)));
    }

    public function approve(ResultSheet $sheet, User $user, ?string $note = null): ResultSheet
    {
        if (! $this->canApprove($user, $sheet)) {
            throw new RuntimeException('This sheet is not waiting for you.');
        }
        $stage = $sheet->stage;
        $next = ResultStage::chain()->first(fn ($s) => ($s->sort_order <=> $stage->sort_order) > 0 || ($s->sort_order === $stage->sort_order && $s->id > $stage->id));
        $sheet->update($next ? ['stage_id' => $next->id] : ['status' => 'approved', 'stage_id' => null]);
        $this->mirror($sheet);
        $this->log($sheet, $user, 'approved', $stage->name, $note);

        return $sheet;
    }

    public function returnToLecturer(ResultSheet $sheet, User $user, ?string $note): ResultSheet
    {
        if (! $this->canApprove($user, $sheet) && ! ($sheet->status === 'approved' && $user->can('Publish results'))) {
            throw new RuntimeException('This sheet is not waiting for you.');
        }
        if (blank($note)) {
            throw new RuntimeException('Say what must be corrected.');
        }
        $stage = $sheet->stage?->name;
        $sheet->update(['status' => 'draft', 'stage_id' => null]);
        $this->mirror($sheet);
        $this->log($sheet, $user, 'returned', $stage, $note);

        return $sheet;
    }

    public function publish(ResultSheet $sheet, User $user): ResultSheet
    {
        if ($sheet->status !== 'approved') {
            throw new RuntimeException('Only an approved sheet can be published.');
        }
        $sheet->update(['status' => 'published', 'published_at' => now()]);
        $this->mirror($sheet);
        $this->log($sheet, $user, 'published');
        $this->audit->note('result_publish', 'results', $user, 'published', $sheet, ['status' => 'approved'], ['status' => 'published', 'course_id' => $sheet->course_id], null, $sheet->academic_session_id);
        $this->tellStudents($sheet);

        return $sheet;
    }

    /** Each student on a newly published sheet is told their result is out (the grade itself stays in the portal). */
    private function tellStudents(ResultSheet $sheet): void
    {
        $sheet->loadMissing('course', 'session', 'semesterType');
        $what = trim("{$sheet->course?->code} {$sheet->course?->title}");
        $when = trim(($sheet->session?->name ?? '') . ' ' . ($sheet->semesterType?->name ?? ''));
        foreach ($sheet->results()->with('student.user')->get() as $result) {
            if ($user = $result->student?->user) {
                $this->messenger->notify($user, 'results', "Your {$sheet->course?->code} result is out",
                    "Your result for {$what}" . ($when ? " ({$when})" : '') . ' has been published. Open My Results to see it.',
                    $result, route('my.results'), 'success', "result:{$sheet->id}:{$result->id}:" . $sheet->published_at?->timestamp);
            }
        }
    }

    /** Formally withdraw a published sheet: it goes back to "approved" and stops counting until published again. */
    public function withdraw(ResultSheet $sheet, User $user, string $reason): ResultSheet
    {
        if ($sheet->status !== 'published') {
            throw new RuntimeException('Only a published sheet can be withdrawn.');
        }
        $sheet->update(['status' => 'approved', 'published_at' => null]);
        $this->mirror($sheet);
        $this->log($sheet, $user, 'withdrawn', null, $reason);

        return $sheet;
    }

    private function mirror(ResultSheet $sheet): void
    {
        $sheet->results()->update(['status' => $sheet->status === 'submitted' ? 'submitted' : $sheet->status]);
    }

    /**
     * Correct one published score. The reason and the old/new values go on the sheet's history. On a sheet with an
     * assessment scheme, $items (code or id => mark) corrects single assessments; without it the CA and exam are spread
     * over the items. Nothing changes unless the corrected result is complete.
     */
    public function amend(CourseResult $result, $ca, $exam, string $reason, User $user, bool $record = true, ?array $items = null): CourseResult
    {
        if ($result->status !== 'published') {
            throw new RuntimeException('Only published results are amended this way. Return the sheet to the lecturer instead.');
        }
        if (blank($reason)) {
            throw new RuntimeException('Give the reason for the correction.');
        }
        $scheme = $result->sheet?->scheme?->loadMissing('items');
        $old = "{$result->ca}/{$result->exam} ({$result->grade})";
        $before = ['ca' => $result->ca, 'exam' => $result->exam, 'grade' => $result->grade] + ($scheme ? ['items' => $this->assessments->snapshot($result, $scheme)] : []);

        DB::transaction(function () use ($result, $ca, $exam, $scheme, $items, $user) {
            $this->apply($result, $ca, $exam, $scheme, $items, $user);
            $result->refresh();
            if ($result->total === null) {
                throw new RuntimeException($scheme ? 'Every assessment needs a mark.' : 'Both the CA and exam scores are required.');
            }
        });
        $after = ['ca' => $result->ca, 'exam' => $result->exam, 'grade' => $result->grade] + ($scheme ? ['items' => $this->assessments->snapshot($result, $scheme)] : []);
        if ($record) {
            $this->audit->note('result_amend', 'results', $user, 'score_amended', $result, $before, $after, $reason, $result->academic_session_id);
            DB::afterCommit(fn () => $this->mailer->resultNotice($result->fresh(), 'amended', $before, $reason, $user));
        }
        if ($result->result_sheet_id) {
            $this->log($result->sheet, $user, 'amended', null, "{$result->student->matric_number}: {$old} to {$result->ca}/{$result->exam} ({$result->grade}). {$reason}");
        }

        return $result;
    }

    /**
     * A programme's results for one level, session and semester: one row per student with each course's score and grade,
     * semester GPA and CGPA (published results only).
     *
     * @return array{courses: Collection, rows: Collection}
     */
    public function broadsheet(int $programmeId, int $level, int $sessionId, int $semesterTypeId): array
    {
        $students = Student::where('programme_id', $programmeId)
            ->whereHas('registrationForms', fn ($q) => $q->where('level', $level)->where('academic_session_id', $sessionId)->where('semester_type_id', $semesterTypeId)->where('status', 'approved'))
            ->orderBy('last_name')->orderBy('first_name')->get();

        $term = CourseResult::with('course')->whereIn('student_id', $students->pluck('id'))->where('status', 'published')
            ->where('academic_session_id', $sessionId)->where('semester_type_id', $semesterTypeId)->get()->groupBy('student_id');
        $courses = $term->flatten()->pluck('course')->unique('id')->sortBy('code')->values();

        $rows = $students->map(function (Student $s) use ($term) {
            $mine = $term->get($s->id, collect());

            return ['student' => $s, 'results' => $mine->keyBy('course_id'), 'units' => (int) $mine->sum('credit_units'), 'gpa' => GradeScale::gpa($mine)] + (fn ($t) => ['cgpa' => $t->cgpa, 'standing' => $t->standing])($this->transcript($s));
        });

        return ['courses' => $courses, 'rows' => $rows];
    }

    /**
     * A department's end-of-semester summary: each student registered that semester sorted into
     * pending (no published result yet), withdraw, probation, carry-over (outstanding failed courses) or passed.
     *
     * @return Collection<string, array{programme: \App\Models\Programme, level: int, rows: Collection}> keyed "programmeId-level"
     */
    public function summary(int $departmentId, ?int $level, int $sessionId, int $semesterTypeId): Collection
    {
        $forms = RegistrationForm::with('student.programme')->where('status', 'approved')->where('academic_session_id', $sessionId)->where('semester_type_id', $semesterTypeId)
            ->when($level, fn ($q) => $q->where('level', $level))
            ->whereHas('student', fn ($q) => $q->whereHas('programme', fn ($p) => $p->where('department_id', $departmentId)))->get();

        return $forms->map(function (RegistrationForm $f) use ($sessionId, $semesterTypeId) {
            $s = $f->student;
            $term = CourseResult::with('course')->where('student_id', $s->id)->where('status', 'published')
                ->where('academic_session_id', $sessionId)->where('semester_type_id', $semesterTypeId)->get();
            $t = $this->transcript($s);
            $passed = CourseResult::where('student_id', $s->id)->where('status', 'published')->where('is_pass', true)->pluck('course_id')->flip();
            $outstanding = CourseResult::with('course')->where('student_id', $s->id)->where('status', 'published')->where('is_pass', false)->get()
                ->reject(fn ($r) => $passed->has($r->course_id))->pluck('course.code')->unique()->sort()->values();
            $bucket = $term->isEmpty() ? 'pending' : match ($t->standing) {
                'Advised to withdraw' => 'withdraw', 'On probation' => 'probation', default => $outstanding->isNotEmpty() ? 'carryover' : 'pass',
            };

            return ['student' => $s, 'level' => (int) $f->level, 'gpa' => GradeScale::gpa($term), 'cgpa' => $t->cgpa, 'standing' => $t->standing,
                'outstanding' => $outstanding, 'bucket' => $bucket, 'pending' => $f->courses()->count() - $term->count()];
        })->sortBy(fn ($r) => $r['student']->matric_number)
            ->groupBy(fn ($r) => $r['student']->programme_id . '-' . $r['level'])
            ->map(fn ($rows) => ['programme' => $rows->first()['student']->programme, 'level' => $rows->first()['level'], 'rows' => $rows->values()])
            ->sortBy(fn ($g) => [$g['programme']->name, $g['level']]);
    }

    // ---- Reading results back --------------------------------------------------------------------------

    /**
     * A student's published results grouped by session and semester, with GPA per semester and the running CGPA.
     * By default each course counts once in the CGPA (its latest published attempt); under the "every attempt" rule
     * (TCP / TNU) every attempt counts. Standing (probation / withdrawal) follows the programme's rules.
     *
     */
    public function transcript(Student $student): Transcript
    {
        $all = CourseResult::with('course', 'session', 'semesterType', 'assessmentScores.item')->where('student_id', $student->id)->where('status', 'published')
            ->get()->sortBy(fn ($r) => [$r->session->name, $r->semesterType->sort_order, $r->id])->values();   // session names sort chronologically (2025/2026 < 2026/2027)

        $terms = $all->groupBy(fn ($r) => $r->academic_session_id . '-' . $r->semester_type_id)->map(fn ($rows) => new TranscriptTerm(
            $rows->first()->session, $rows->first()->semesterType, $rows->sortBy('course.code')->values(), (int) $rows->sum('credit_units'), (float) GradeScale::gpa($rows),
        ))->values();

        $rules = $this->rules->forStudent($student);
        $latest = $all->groupBy('course_id')->map->last()->values();
        $counted = $rules['cgpa_method'] === 'all' ? $all : $latest;

        // Totals brought forward from the old records system count as if they were results here.
        $bf = $student->legacyBalance;
        $units = (int) $counted->filter(fn ($r) => $r->grade !== null && $r->points !== null)->sum('credit_units') + (int) ($bf?->tnu ?? 0);
        $points = $counted->filter(fn ($r) => $r->grade !== null && $r->points !== null)->sum(fn ($r) => $r->points * $r->credit_units) + (float) ($bf?->tcp ?? 0);
        $cgpa = $units > 0 ? round($points / $units, 2) : 0.0;
        $has = $latest->isNotEmpty() || $bf;

        return new Transcript(
            terms: $terms,
            cgpa: $cgpa,
            units: (int) $counted->sum('credit_units') + (int) ($bf?->tnu ?? 0),
            tcp: round($points, 2),
            earned: (int) $latest->where('is_pass', true)->sum('credit_units') + (int) ($bf?->units_passed ?? 0),
            class: $has ? GradeScale::classOf($cgpa) : null,
            standing: \App\Support\AcademicRules::standing($rules, $cgpa, (bool) $has),
            method: $rules['cgpa_method'],
            broughtForward: $bf ? BroughtForward::fromBalance($bf) : null,
        );
    }
}
