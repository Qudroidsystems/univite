<?php

namespace App\Services;

use App\Models\AssessmentAssignment;
use App\Models\AssessmentItem;
use App\Models\AssessmentScheme;
use App\Models\AssessmentScore;
use App\Models\Course;
use App\Models\CourseResult;
use App\Models\ResultSheet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Assessment schemes: building them, assigning them to the university / a faculty / a department / a course, finding the
 * scheme a score sheet uses, and turning per-item marks into a result's CA and exam.
 *
 * The most specific assignment wins: course < department < faculty < university, and at each level an assignment for
 * the sheet's own session beats one for every session. A sheet takes its scheme when it is opened and keeps it once a
 * mark is entered, so changing an assignment later never changes marks already given.
 */
class AssessmentService
{
    /** Scope types from most to least specific. */
    private const ORDER = ['course', 'department', 'faculty', 'university'];

    /** Every assignment with its scheme, read once per request. */
    private ?Collection $assignments = null;

    // ---- Building schemes ------------------------------------------------------------------------------

    /**
     * Check a list of items before saving: names, unique codes, marks above 0, and a total of exactly 100. When the
     * admin set a CA total and an exam total (for example 40 and 60), they must add up to 100 and each part's
     * assessments must add up to its total. An assessment's mark may be left blank (CA1, CA2, CA3 with no "out of"):
     * that needs the CA and exam totals, and the part's marks then only have to stay within its total.
     *
     * @param  array<int, array{name?: mixed, code?: mixed, component?: mixed, max_score?: mixed}>  $items
     * @return array<int, array{name: string, code: string, component: string, max_score: float}>
     */
    public function cleanItems(array $items, mixed $caTotal = null, mixed $examTotal = null): array
    {
        $out = [];
        $codes = [];
        foreach (array_values($items) as $n => $i) {
            $name = trim((string) ($i['name'] ?? ''));
            $max = $i['max_score'] ?? null;
            if ($name === '' && blank($max)) {
                continue;   // an empty row the form left behind
            }
            $row = $n + 1;
            if ($name === '') {
                throw new RuntimeException("Item {$row} needs a name.");
            }
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($i['code'] ?? '')));
            if ($code === '') {
                $code = $this->codeFor($name, $codes);
            }
            if (in_array($code, $codes, true)) {
                throw new RuntimeException("Two items use the short code {$code}. Give each item its own code.");
            }
            if (! blank($max) && (! is_numeric($max) || $max <= 0 || $max > 100)) {
                throw new RuntimeException("{$name}: the mark must be a number above 0 and at most 100, or left blank.");
            }
            $component = ($i['component'] ?? 'ca') === 'exam' ? 'exam' : 'ca';
            $codes[] = $code;
            $out[] = ['name' => mb_substr($name, 0, 80), 'code' => mb_substr($code, 0, 20), 'component' => $component, 'max_score' => blank($max) ? null : round((float) $max, 2)];
        }

        if (! $out) {
            throw new RuntimeException('Add at least one assessment.');
        }
        [$caTotal, $examTotal] = $this->totals($caTotal, $examTotal);
        $open = array_filter($out, fn ($i) => $i['max_score'] === null);
        if ($open && $caTotal === null) {
            throw new RuntimeException('Set the CA total and the exam total (for example 40 and 60) when an assessment has no mark of its own.');
        }
        if ($caTotal !== null) {
            foreach (['ca' => [$caTotal, 'CA'], 'exam' => [$examTotal, 'exam']] as $component => [$want, $label]) {
                $part = array_filter($out, fn ($i) => $i['component'] === $component);
                $have = round(array_sum(array_map(fn ($i) => (float) $i['max_score'], $part)), 2);
                $partOpen = count(array_filter($part, fn ($i) => $i['max_score'] === null));
                if ($partOpen && $have > $want + 0.001) {
                    throw new RuntimeException("The {$label} assessments with their own marks add up to " . self::num($have) . ', more than the ' . $label . ' total of ' . self::num($want) . '.');
                }
                if (! $partOpen && abs($have - $want) > 0.001) {
                    throw new RuntimeException("The {$label} assessments must add up to the {$label} total of " . self::num($want) . '. They add up to ' . self::num($have) . ' now. Or leave their marks blank, so any marks adding up to the total are allowed.');
                }
            }
            if ($open) {
                return $out;   // the totals add up to 100, and each part stays within its total
            }
        }
        $total = round(array_sum(array_column($out, 'max_score')), 2);
        if (abs($total - 100) > 0.001) {
            throw new RuntimeException("The assessments must add up to 100. They add up to {$total} now.");
        }

        return $out;
    }

    /**
     * The CA and exam totals the admin typed: both blank (worked out from the assessments) or both set and adding up
     * to 100.
     *
     * @return array{0: ?float, 1: ?float}
     */
    public function totals(mixed $caTotal, mixed $examTotal): array
    {
        $caTotal = blank($caTotal) ? null : $caTotal;
        $examTotal = blank($examTotal) ? null : $examTotal;
        if ($caTotal === null && $examTotal === null) {
            return [null, null];
        }
        if ($caTotal === null || $examTotal === null) {
            [$caTotal, $examTotal] = $caTotal === null ? [100 - (float) $examTotal, $examTotal] : [$caTotal, 100 - (float) $caTotal];
        }
        foreach ([[$caTotal, 'CA'], [$examTotal, 'Exam']] as [$v, $label]) {
            if (! is_numeric($v) || $v < 0 || $v > 100) {
                throw new RuntimeException("The {$label} total must be a number from 0 to 100.");
            }
        }
        if (abs((float) $caTotal + (float) $examTotal - 100) > 0.001) {
            throw new RuntimeException('The CA total and the exam total must add up to 100 (for example 40 and 60).');
        }

        return [round((float) $caTotal, 2), round((float) $examTotal, 2)];
    }

    private static function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** "Test 1" => T1, "Mid-semester exam" => MSE; numbered when taken. */
    private function codeFor(string $name, array $taken): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $base = strtoupper(implode('', array_map(fn ($w) => ctype_digit($w) ? $w : $w[0], $words))) ?: 'A';
        $code = $base;
        for ($k = 2; in_array($code, $taken, true); $k++) {
            $code = $base . $k;
        }

        return $code;
    }

    /** Create a scheme, or update one. Items cannot change once marks have been entered against them. */
    public function save(?AssessmentScheme $scheme, array $data, array $items, User $user): AssessmentScheme
    {
        $clean = $this->cleanItems($items, $data['ca_total'] ?? null, $data['exam_total'] ?? null);
        [$caTotal, $examTotal] = $this->totals($data['ca_total'] ?? null, $data['exam_total'] ?? null);

        return DB::transaction(function () use ($scheme, $data, $clean, $user, $caTotal, $examTotal) {
            $fields = ['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true),
                'ca_total' => $caTotal, 'exam_total' => $examTotal];
            if ($scheme && $scheme->isLocked()) {
                $scheme->load('items');
                $now = $scheme->items->map(fn ($i) => [$i->name, $i->code, $i->component, $i->max_score === null ? null : (float) $i->max_score])->all();
                $new = array_map(fn ($i) => [$i['name'], $i['code'], $i['component'], $i['max_score']], $clean);
                if ($now !== $new) {
                    throw new RuntimeException('Marks have already been entered with this scheme, so its assessments can no longer change. Duplicate it, change the copy and assign the copy: score sheets still being filled in then move to it, keeping their marks.');
                }
                $scheme->update($fields);

                return $scheme;
            }

            $scheme = $scheme ? tap($scheme)->update($fields) : AssessmentScheme::create($fields + ['created_by' => $user->id]);
            $keep = [];
            foreach ($clean as $n => $i) {
                $item = $scheme->items()->updateOrCreate(['code' => $i['code']], $i + ['sort_order' => $n + 1]);
                $keep[] = $item->id;
            }
            $scheme->items()->whereNotIn('id', $keep)->delete();

            return $scheme->load('items');
        });
    }

    public function duplicate(AssessmentScheme $scheme, User $user): AssessmentScheme
    {
        return DB::transaction(function () use ($scheme, $user) {
            $copy = AssessmentScheme::create(['name' => mb_substr($scheme->name . ' (copy)', 0, 120), 'description' => $scheme->description,
                'ca_total' => $scheme->ca_total, 'exam_total' => $scheme->exam_total, 'is_active' => true, 'created_by' => $user->id]);
            foreach ($scheme->items as $i) {
                $copy->items()->create($i->only(['name', 'code', 'component', 'max_score', 'sort_order']));
            }

            return $copy->load('items');
        });
    }

    /** Why a scheme cannot be deleted, or null. */
    public function blockDelete(AssessmentScheme $scheme): ?string
    {
        if ($scheme->sheets()->exists()) {
            return 'Score sheets use this scheme. Switch it off instead so it is not given to new sheets.';
        }

        return null;
    }

    // ---- Assigning ---------------------------------------------------------------------------------------

    public function assign(AssessmentScheme $scheme, string $scopeType, ?int $scopeId, ?int $sessionId, User $user): AssessmentAssignment
    {
        if (! isset(AssessmentAssignment::SCOPES[$scopeType])) {
            throw new RuntimeException('Choose where the scheme applies.');
        }
        if ($scopeType !== 'university' && ! $scopeId) {
            throw new RuntimeException('Choose the ' . strtolower(AssessmentAssignment::SCOPES[$scopeType]) . '.');
        }
        if (! $scheme->is_active) {
            throw new RuntimeException('This scheme is switched off. Switch it on before assigning it.');
        }
        if ($scheme->items()->doesntExist()) {
            throw new RuntimeException('This scheme has no assessments yet.');
        }

        $assignment = AssessmentAssignment::updateOrCreate(
            ['scope_type' => $scopeType, 'scope_id' => $scopeType === 'university' ? null : $scopeId, 'academic_session_id' => $sessionId],
            ['assessment_scheme_id' => $scheme->id, 'assigned_by' => $user->id],
        );
        $this->assignments = null;
        $this->lastRefresh = $this->refreshOpenSheets($user);

        return $assignment;
    }

    public function unassign(AssessmentAssignment $assignment, ?User $user = null): void
    {
        $assignment->delete();
        $this->assignments = null;
        $this->lastRefresh = $this->refreshOpenSheets($user);
    }

    /** What the last assign / unassign did to the score sheets (see refreshOpenSheets). */
    public ?array $lastRefresh = null;

    /**
     * Score sheets still being filled in follow a change of scheme straight away: a sheet with no mark simply takes the
     * new scheme; a sheet with marks moves to it with its marks (an assessment with the same code keeps its mark, the
     * rest of a CA or exam is spread over the new assessments), logged on the sheet. A sheet whose marks cannot fit
     * the new scheme stays as it is and is reported. Submitted, approved and published sheets are not touched.
     *
     * @return array{switched: int, moved: int, kept: array<int, array{sheet: ResultSheet, reason: string}>, locked: int}
     */
    public function refreshOpenSheets(?User $user = null): array
    {
        $out = ['switched' => 0, 'moved' => 0, 'kept' => [], 'locked' => 0];
        foreach (ResultSheet::with('course.department', 'scheme.items')->whereIn('status', ['draft', 'submitted', 'approved'])->get() as $sheet) {
            if (! $sheet->course) {
                continue;
            }
            [$scheme] = $this->resolve($sheet->course, $sheet->academic_session_id);
            if ((int) $sheet->assessment_scheme_id === (int) $scheme?->id) {
                continue;
            }
            if (! $sheet->isEditable()) {
                $out['locked']++;

                continue;
            }
            if (! $this->hasMarks($sheet)) {
                $this->attach($sheet, false);
                $out['switched']++;

                continue;
            }
            $from = $sheet->scheme?->name ?? 'plain CA and exam';
            try {
                $this->convert($sheet, $scheme, $user, true);
                \App\Models\ResultSheetEvent::create(['result_sheet_id' => $sheet->id, 'user_id' => $user?->id, 'action' => 'scheme', 'created_at' => now(),
                    'note' => 'Assessments changed from ' . $from . ' to ' . ($scheme?->name ?? 'plain CA and exam') . ' (Assessment Schemes). Marks already entered were kept; check them.']);
                $out['moved']++;
            } catch (RuntimeException $e) {
                $out['kept'][] = ['sheet' => $sheet, 'reason' => $e->getMessage()];
            }
        }

        return $out;
    }

    /**
     * Sheets not on the scheme their course uses now: drafts whose marks could not be moved, and sheets already
     * submitted or approved (return them to the lecturer to change their assessments). Published sheets are left out.
     *
     * @return \Illuminate\Support\Collection<int, array{sheet: ResultSheet, now: ?AssessmentScheme}>
     */
    public function outdated(): Collection
    {
        return ResultSheet::with('course.department', 'scheme', 'session', 'semesterType')->whereIn('status', ['draft', 'submitted', 'approved'])->get()
            ->map(function ($sheet) {
                if (! $sheet->course) {
                    return null;
                }
                [$scheme] = $this->resolve($sheet->course, $sheet->academic_session_id);

                return (int) $sheet->assessment_scheme_id === (int) $scheme?->id ? null : ['sheet' => $sheet, 'now' => $scheme];
            })->filter()->values();
    }

    /** The scheme a sheet's course uses now, when it differs from the sheet's own (null when they agree). */
    public function newerScheme(ResultSheet $sheet): array
    {
        [$scheme] = $this->resolve($sheet->course ?? Course::findOrFail($sheet->course_id), $sheet->academic_session_id);

        return (int) $sheet->assessment_scheme_id === (int) $scheme?->id ? ['differs' => false, 'scheme' => $scheme] : ['differs' => true, 'scheme' => $scheme];
    }

    // ---- Which scheme a sheet uses -----------------------------------------------------------------------

    /**
     * The scheme for a course in a session and the assignment it comes from, or [null, null] when nothing applies
     * (the sheet then uses the plain CA / exam split from Result Settings).
     *
     * @return array{0: ?AssessmentScheme, 1: ?AssessmentAssignment}
     */
    public function resolve(Course $course, ?int $sessionId): array
    {
        $course->loadMissing('department');
        $ids = ['course' => $course->id, 'department' => $course->department_id, 'faculty' => $course->department?->faculty_id, 'university' => null];

        $this->assignments ??= AssessmentAssignment::with('scheme.items')->get();
        $candidates = $this->assignments
            ->filter(fn ($a) => $a->academic_session_id === null || ($sessionId && (int) $a->academic_session_id === (int) $sessionId))
            ->filter(fn ($a) => array_key_exists($a->scope_type, $ids) && ($a->scope_type === 'university' ? $a->scope_id === null : (int) $a->scope_id === (int) $ids[$a->scope_type]))
            ->filter(fn ($a) => $a->scheme?->is_active && $a->scheme->items->isNotEmpty());

        $best = $candidates->sortBy(fn ($a) => array_search($a->scope_type, self::ORDER, true) * 2 + ($a->academic_session_id ? 0 : 1))->first();

        return [$best?->scheme, $best];
    }

    /** Whether any mark (per item, or a plain CA / exam) has been entered on the sheet. */
    public function hasMarks(ResultSheet $sheet): bool
    {
        return $sheet->results()->where(fn ($q) => $q->whereNotNull('ca')->orWhereNotNull('exam'))->exists()
            || AssessmentScore::whereIn('course_result_id', $sheet->results()->select('id'))->exists();
    }

    /** Give a draft sheet without marks the scheme that applies to it now. A sheet with marks keeps what it has. */
    public function attach(ResultSheet $sheet, bool $check = true): ResultSheet
    {
        if (! $sheet->isEditable() || ($check && $this->hasMarks($sheet))) {
            return $sheet;
        }
        [$scheme] = $this->resolve($sheet->course ?? Course::findOrFail($sheet->course_id), $sheet->academic_session_id);
        if ((int) $sheet->assessment_scheme_id !== (int) $scheme?->id) {
            $sheet->update(['assessment_scheme_id' => $scheme?->id]);
            $sheet->setRelation('scheme', $scheme);
        }

        return $sheet;
    }

    /**
     * Put a draft sheet on a scheme even though marks are already in (for example the lecturer started before a
     * scheme was assigned). Every student's CA and exam so far are spread over the new scheme's assessments; a sheet
     * whose CA or exam no longer fits the new scheme is refused with the students named. $scheme = null puts the
     * sheet back on the plain CA / exam split.
     *
     * @return int students whose marks were moved
     */
    public function convert(ResultSheet $sheet, ?AssessmentScheme $scheme, ?User $user = null, bool $strict = false): int
    {
        if (! $sheet->isEditable()) {
            throw new RuntimeException('Only a draft sheet can change its assessments. Return it to the lecturer first.');
        }
        if ($scheme && (! $scheme->is_active || $scheme->items()->doesntExist())) {
            throw new RuntimeException('Choose a scheme that is switched on and has assessments.');
        }
        $scheme?->load('items');
        $rows = $sheet->results()->with('student')->get();

        if ($scheme) {
            $tooHigh = $rows->filter(fn ($r) => ($r->ca !== null && $r->ca > $scheme->caMax() + 0.001) || ($r->exam !== null && $r->exam > $scheme->examMax() + 0.001));
            if ($tooHigh->isNotEmpty()) {
                throw new RuntimeException('These students have marks above what ' . $scheme->name . ' allows (CA ' . $scheme->caMax() . ', exam ' . $scheme->examMax() . '): '
                    . $tooHigh->map(fn ($r) => $r->student->matric_number . ' (' . $r->ca . '/' . $r->exam . ')')->implode(', ') . '.');
            }
        }

        // The marks on the old assessments, by code and part, so an assessment kept in the new scheme keeps its mark.
        $old = $sheet->scheme?->loadMissing('items');
        $oldMarks = [];
        if ($old) {
            foreach ($rows as $row) {
                $m = $this->marks($row, $old);
                foreach ($old->items as $i) {
                    if ($m[$i->id] !== null) {
                        $oldMarks[$row->id][$i->component][strtoupper($i->code)] = (float) $m[$i->id];
                    }
                }
            }
        }

        // Moving on its own (an assignment changed), a sheet is not moved when a mark would have nowhere to go: the
        // new scheme has no CA (or no exam) for marks already entered, or a part still being marked loses assessments.
        if ($strict && $scheme) {
            $lose = $rows->filter(function ($r) use ($scheme, $oldMarks) {
                foreach (['ca' => $r->ca, 'exam' => $r->exam] as $component => $total) {
                    $mine = array_filter($oldMarks[$r->id][$component] ?? [], fn ($v) => $v > 0);
                    $codes = $scheme->items->where('component', $component)->map(fn ($i) => strtoupper($i->code))->all();
                    if (! $codes && ($mine || (float) $total > 0)) {
                        return true;
                    }
                    if ($total === null && array_diff(array_keys($mine), $codes)) {
                        return true;
                    }
                }

                return false;
            });
            if ($lose->isNotEmpty()) {
                throw new RuntimeException('marks already entered for ' . $lose->count() . ' student(s) (' . $lose->take(5)->map(fn ($r) => $r->student->matric_number)->implode(', ')
                    . ') have no place in ' . $scheme->name . '. Move the sheet by hand on its page, or finish the marks first.');
            }
        }

        return DB::transaction(function () use ($sheet, $scheme, $rows, $user, $oldMarks) {
            AssessmentScore::whereIn('course_result_id', $rows->pluck('id'))->delete();
            $sheet->update(['assessment_scheme_id' => $scheme?->id]);
            $moved = 0;
            if ($scheme) {
                foreach ($rows as $row) {
                    $row->unsetRelation('assessmentScores');
                    $mine = $oldMarks[$row->id] ?? [];
                    if ($row->ca === null && $row->exam === null && ! $mine) {
                        continue;
                    }
                    // Part by part: the new assessments all found under the same code (and adding up to the part's
                    // total, when there is one) keep their marks; otherwise the part's total is spread over them.
                    $keep = [];
                    $spread = ['ca' => null, 'exam' => null];
                    foreach (['ca' => $row->ca, 'exam' => $row->exam] as $component => $total) {
                        $newItems = $scheme->items->where('component', $component);
                        $map = [];
                        foreach ($newItems as $it) {
                            $v = $mine[$component][strtoupper($it->code)] ?? null;
                            if ($v !== null && $v <= $it->limit($scheme) + 0.001) {
                                $map[$it->id] = $v;
                            }
                        }
                        $all = $newItems->isNotEmpty() && count($map) === $newItems->count();
                        if ($all && ($total === null || abs(array_sum($map) - (float) $total) < 0.01)) {
                            $keep += $map;
                        } elseif ($total !== null) {
                            $spread[$component] = $total;
                        } else {
                            $keep += $map;   // a part still being marked: the marks that carry over
                        }
                    }
                    if ($spread['ca'] !== null || $spread['exam'] !== null) {
                        $this->distribute($row, $scheme, $spread['ca'], $spread['exam'], $user);
                    }
                    if ($keep) {
                        $this->applyItems($row, $scheme, $keep, $user);
                    }
                    [$ca, $exam] = $this->components($row, $scheme);
                    $total = ($ca !== null && $exam !== null) ? round($ca + $exam, 2) : null;
                    $g = \App\Support\GradeScale::gradeResult($row, $total);
                    $attrs = ['ca' => $ca, 'exam' => $exam, 'total' => $total, 'grade' => $g['grade'] ?? null, 'points' => $g['points'] ?? null, 'is_pass' => $g['is_pass'] ?? null];
                    if ($row->absent && \App\Services\ResultService::absentPolicy() === 'incomplete') {
                        $attrs = ['ca' => $ca, 'exam' => $exam, 'total' => null, 'grade' => CourseResult::ABSENT_GRADE, 'points' => null, 'is_pass' => false];
                    }
                    $row->update($attrs);
                    $moved++;
                }
            }

            return $moved;
        });
    }

    // ---- Marks ----------------------------------------------------------------------------------------------

    /** @return Collection<int, ?float> item id => mark (null when not entered) for every item of the scheme */
    public function marks(CourseResult $row, AssessmentScheme $scheme): Collection
    {
        $have = $row->relationLoaded('assessmentScores') ? $row->assessmentScores : $row->assessmentScores()->get();
        $have = $have->keyBy('assessment_item_id');

        return $scheme->items->mapWithKeys(fn ($i) => [$i->id => $have->has($i->id) ? (float) $have[$i->id]->score : null]);
    }

    /**
     * Set some or all of a student's item marks (item id or code => mark; blank clears). Everything is checked before
     * anything is written. Returns the CA and exam the items now add up to; a component stays null until every one of
     * its items has a mark, and a component with no items counts 0.
     *
     * @return array{0: ?float, 1: ?float}
     */
    public function applyItems(CourseResult $row, AssessmentScheme $scheme, array $marks, ?User $user = null): array
    {
        $scheme->loadMissing('items');
        $byId = $scheme->items->keyBy('id');
        $byCode = $scheme->items->keyBy(fn ($i) => strtoupper($i->code));
        $set = [];
        foreach ($marks as $key => $v) {
            $item = $byId->get(is_numeric($key) ? (int) $key : -1) ?? $byCode->get(strtoupper((string) $key));
            if (! $item) {
                throw new RuntimeException("{$key} is not an assessment on this sheet.");
            }
            $v = ($v === null || $v === '') ? null : $v;
            $limit = $item->limit($scheme);
            if ($v !== null && (! is_numeric($v) || $v < 0 || $v > $limit + 0.001)) {
                throw new RuntimeException("{$item->name} must be a number from 0 to " . self::num($limit) . '.');
            }
            $set[$item->id] = $v === null ? null : round((float) $v, 2);
        }
        // A part's marks (those kept and those just typed) may not add up to more than its total.
        $now = $this->marks($row, $scheme)->all();
        foreach ($set as $id => $v) {
            $now[$id] = $v;
        }
        foreach (['ca' => 'CA', 'exam' => 'Exam'] as $component => $label) {
            $ids = $scheme->items->where('component', $component)->pluck('id')->all();
            if (! $ids || ! array_intersect($ids, array_keys($set))) {
                continue;
            }
            $sum = round(array_sum(array_map(fn ($id) => (float) ($now[$id] ?? 0), $ids)), 2);
            $cap = $scheme->partTotal($component);
            if ($sum > $cap + 0.001) {
                throw new RuntimeException("The {$label} marks add up to " . self::num($sum) . ', more than the ' . $label . ' total of ' . self::num($cap) . '.');
            }
        }

        foreach ($set as $itemId => $v) {
            if ($v === null) {
                AssessmentScore::where('course_result_id', $row->id)->where('assessment_item_id', $itemId)->delete();
            } else {
                AssessmentScore::updateOrCreate(['course_result_id' => $row->id, 'assessment_item_id' => $itemId], ['score' => $v, 'entered_by' => $user?->id]);
            }
        }
        $row->unsetRelation('assessmentScores');

        return $this->components($row, $scheme);
    }

    /**
     * Save what a lecturer typed in one row, part by part (CA, then exam). A part whose assessments have any mark is
     * worked out from them, as before. A part whose assessments are all blank but whose total was typed takes that total
     * directly, and its assessment marks are cleared: lecturers may give a CA or exam total without the breakdown. A part
     * with neither its assessments nor its total in $items / the totals is left as it is. A total typed next to marked
     * assessments is ignored (a downloaded sheet still shows the old total after an assessment is changed).
     *
     * @param  array<int|string, mixed>  $items  item id or code => mark
     * @return array{0: ?float, 1: ?float}
     */
    public function applyMixed(CourseResult $row, AssessmentScheme $scheme, array $items, mixed $ca, mixed $exam, ?User $user = null): array
    {
        $scheme->loadMissing('items');
        $byId = $scheme->items->keyBy('id');
        $byCode = $scheme->items->keyBy(fn ($i) => strtoupper($i->code));
        $given = [];
        foreach ($items as $key => $v) {
            $item = $byId->get(is_numeric($key) ? (int) $key : -1) ?? $byCode->get(strtoupper((string) $key));
            if (! $item) {
                throw new RuntimeException("{$key} is not an assessment on this sheet.");
            }
            $given[$item->id] = $v;
        }

        $plan = [];
        foreach (['ca' => [$ca, 'CA', $row->ca], 'exam' => [$exam, 'Exam', $row->exam]] as $component => [$total, $label, $now]) {
            $part = $scheme->items->where('component', $component);
            if ($part->isEmpty()) {
                $plan[$component] = ['fixed', 0.0];
                continue;
            }
            $mine = array_intersect_key($given, $part->keyBy('id')->all());
            $filled = array_filter($mine, fn ($v) => ! blank($v));
            $total = blank($total) ? null : $total;
            $max = $scheme->partTotal($component);
            if ($filled) {
                $plan[$component] = ['items', $mine];
            } elseif ($total !== null) {
                if (! is_numeric($total) || $total < 0 || $total > $max) {
                    throw new RuntimeException("{$label} total must be a number from 0 to " . self::num($max) . '.');
                }
                $plan[$component] = ['total', round((float) $total, 2), $part->pluck('id')->all()];
            } elseif ($mine) {
                $plan[$component] = ['items', $mine];
            } else {
                $plan[$component] = ['fixed', $now === null ? null : (float) $now];
            }
        }

        $marks = [];
        foreach ($plan as $p) {
            if ($p[0] === 'items') {
                $marks += $p[1];
            }
        }
        if ($marks) {
            $this->applyItems($row, $scheme, $marks, $user);
        }
        [$fromCa, $fromExam] = $this->components($row, $scheme);
        $out = [];
        foreach (['ca' => $fromCa, 'exam' => $fromExam] as $component => $fromItems) {
            $p = $plan[$component];
            if ($p[0] === 'total') {
                AssessmentScore::where('course_result_id', $row->id)->whereIn('assessment_item_id', $p[2])->delete();
                $out[] = $p[1];
            } elseif ($p[0] === 'fixed') {
                $out[] = $p[1];
            } else {
                $out[] = $fromItems;
            }
        }
        $row->unsetRelation('assessmentScores');

        return $out;
    }

    /**
     * Spread a CA and exam over the scheme's items in proportion to each item's marks (used when only a CA and exam
     * are known: teaching-practice scores, corrections from a result query, an upload with CA and Exam columns).
     *
     * @return array{0: ?float, 1: ?float}
     */
    public function distribute(CourseResult $row, AssessmentScheme $scheme, $ca, $exam, ?User $user = null): array
    {
        $scheme->loadMissing('items');
        $marks = [];
        foreach (['ca' => [$ca, 'CA'], 'exam' => [$exam, 'Exam']] as $component => [$value, $label]) {
            $items = $scheme->items->where('component', $component)->values();
            $max = $scheme->partTotal($component);
            // An assessment with no mark of its own takes an equal share of what the others leave.
            $fixed = round((float) $items->sum('max_score'), 2);
            $openCount = $items->filter->isOpen()->count();
            $weight = fn ($item) => $item->max_score ?? ($openCount ? max(0, $max - $fixed) / $openCount : 0);
            $value = ($value === null || $value === '') ? null : $value;
            if ($value !== null && (! is_numeric($value) || $value < 0 || $value > $max)) {
                throw new RuntimeException("{$label} score must be a number from 0 to " . rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') . '.');
            }
            $left = $value === null ? null : round((float) $value, 2);
            foreach ($items as $n => $item) {
                if ($left === null) {
                    $marks[$item->id] = null;
                    continue;
                }
                $cap = $item->limit($scheme);
                $share = $n === $items->count() - 1 ? $left : ($max > 0 ? min($cap, round((float) $value * $weight($item) / $max, 2)) : 0);
                $share = max(0, min($cap, round($share, 2)));
                $marks[$item->id] = $share;
                $left = round($left - $share, 2);
            }
        }

        return $this->applyItems($row, $scheme, $marks, $user);
    }

    /** @return array{0: ?float, 1: ?float} CA and exam from the stored item marks */
    public function components(CourseResult $row, AssessmentScheme $scheme): array
    {
        $marks = $this->marks($row, $scheme);
        $out = [];
        foreach (['ca', 'exam'] as $component) {
            $ids = $scheme->items->where('component', $component)->pluck('id');
            $values = $ids->map(fn ($id) => $marks[$id] ?? null);
            $out[] = $ids->isEmpty() ? 0.0 : ($values->contains(null) ? null : round((float) $values->sum(), 2));
        }

        return $out;
    }

    /** Snapshot of a result's item marks for the history: [code => mark|null]. */
    public function snapshot(CourseResult $row, ?AssessmentScheme $scheme): ?array
    {
        if (! $scheme) {
            return null;
        }
        $marks = $this->marks($row, $scheme);

        return $scheme->items->mapWithKeys(fn ($i) => [$i->code => $marks[$i->id]])->all();
    }
}
