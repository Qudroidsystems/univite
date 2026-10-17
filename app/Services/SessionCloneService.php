<?php

namespace App\Services;

use App\Data\Sessions\SemesterPair;
use App\Models\AcademicSession;
use App\Models\AssessmentAssignment;
use App\Models\Bill;
use App\Models\CloneItem;
use App\Models\CourseAssignment;
use App\Models\Invoice;
use App\Models\OperationBatch;
use App\Models\PeriodSetting;
use App\Models\ResultSheet;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\SetupSnapshot;
use App\Models\User;
use App\Services\Setup\SetupSnapshots;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copy a session's configuration into another session (an existing one, or a new one made from it), or one semester's
 * into another semester. Only setup is copied: never registrations, scores, results, payments, invoices or any student
 * data. The source is read, never written. Running it twice does nothing the second time. Before anything changes, the
 * destination's setup is saved as an automatic snapshot, and the copy itself can be undone.
 */
class SessionCloneService
{
    public const CATEGORIES = [
        'registration' => ['Course registration settings', 'What the session and each semester set for themselves: registration open or closed, adviser approval, clearance and fee checks, late registration.'],
        'windows'      => ['Registration windows', 'Copies each semester\'s registration open/close dates into the matching semester, moved forward by the year (or semester) difference.'],
        'bills'        => ['School bills (fee schedule)', 'Copies the fee schedule as inactive bills so they can be reviewed and switched on. Payment windows move forward by the year difference.'],
        'assignments'  => ['Course lecturers', 'Copies which lecturer takes which course each semester.'],
        'assessments'  => ['Assessment scheme choices', 'Copies which assessment scheme the university, a faculty, department or course uses in the session.'],
    ];

    /** What can be copied from one semester to another (the rest belongs to the whole session). */
    public const SEMESTER_CATEGORIES = ['registration', 'windows', 'bills', 'assignments'];

    public function __construct(private OperationAudit $audit, private SetupSnapshots $snapshots)
    {
    }

    /** The categories that apply to this kind of copy. @return array<string, array{0: string, 1: string}> */
    public static function categoriesFor(bool $semesters): array
    {
        return $semesters ? array_intersect_key(self::CATEGORIES, array_flip(self::SEMESTER_CATEGORIES)) : self::CATEGORIES;
    }

    /**
     * Start a new session from an existing one: same semesters, dates moved forward by the year difference. The new
     * session is a draft; copy its setup with run() afterwards.
     */
    public function createFrom(AcademicSession $source, string $name, ?User $by = null): AcademicSession
    {
        if (! preg_match('/^\d{4}\/\d{4}$/', $name)) {
            throw new RuntimeException('Write the new session as YYYY/YYYY, e.g. 2026/2027.');
        }
        if (AcademicSession::where('name', $name)->exists()) {
            throw new RuntimeException("{$name} already exists; choose it as the destination instead.");
        }
        $years = $this->yearsBetween($source->name, $name);

        return DB::transaction(function () use ($source, $name, $years) {
            $session = AcademicSession::create([
                'name' => $name, 'status' => 'draft', 'is_current' => false,
                'start_date' => $source->start_date?->copy()->addYears($years), 'end_date' => $source->end_date?->copy()->addYears($years),
            ]);
            $semesters = $source->semesters()->get();
            if ($semesters->isEmpty()) {
                foreach (SemesterType::options() as $order => $semName) {
                    Semester::create(['academic_session_id' => $session->id, 'name' => $semName, 'sort_order' => $order]);
                }
            }
            foreach ($semesters as $s) {
                Semester::create([
                    'academic_session_id' => $session->id, 'name' => $s->name, 'sort_order' => $s->sort_order,
                    'start_date' => $s->start_date?->copy()->addYears($years), 'end_date' => $s->end_date?->copy()->addYears($years),
                ]);
            }

            return $session;
        });
    }

    /**
     * What a clone would do, without doing anything.
     *
     * @param string[] $categories
     * @return array{rows: array<string, array{create: int, skip: int, notes: string[]}>, problems: string[], total: int}
     */
    public function preview(AcademicSession $source, AcademicSession $dest, array $categories, ?SemesterPair $pair = null): array
    {
        $problems = $this->guard($source, $dest, $categories, $pair);
        $rows = [];
        foreach (array_keys(self::categoriesFor((bool) $pair)) as $category) {
            if (in_array($category, $categories, true) && ! $problems) {
                $rows[$category] = $this->plan($category, $source, $dest, $pair);
            }
        }

        return ['rows' => $rows, 'problems' => $problems, 'total' => (int) collect($rows)->sum('create')];
    }

    /** @param string[] $categories */
    public function run(AcademicSession $source, AcademicSession $dest, array $categories, User $by, ?SemesterPair $pair = null): OperationBatch
    {
        return $this->copy($source, $dest, $categories, $by, $pair, true);
    }

    /** @param string[] $categories */
    private function copy(AcademicSession $source, AcademicSession $dest, array $categories, User $by, ?SemesterPair $pair, bool $saveFirst): OperationBatch
    {
        $preview = $this->preview($source, $dest, $categories, $pair);
        if ($preview['problems']) {
            throw new RuntimeException(implode(' ', $preview['problems']));
        }

        $what = $pair ? "{$source->name} {$pair->from->name} → {$dest->name} {$pair->to->name}" : "{$source->name} → {$dest->name}";
        $saved = ! $saveFirst ? null : $this->snapshots->take("Before copying {$what}", $dest, array_keys($preview['rows']), $by, 'auto');
        $batch = $this->audit->begin('session_clone', 'sessions', $by, $dest->id, null, array_filter([
            'source_session' => $source->name, 'destination_session' => $dest->name, 'categories' => array_values(array_keys($preview['rows'])),
            'semesters' => $pair?->label(), 'saved_first_as' => $saved?->id, 'new_session' => $saveFirst ? null : true,
        ]));
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($preview, $source, $dest, $batch, $pair, &$created, &$skipped) {
            foreach (array_keys($preview['rows']) as $category) {
                $plan = $this->plan($category, $source, $dest, $pair);
                $skipped += $plan['skip'];
                foreach ($plan['items'] as $item) {
                    $this->apply($category, $item, $dest, $batch);
                    $created++;
                }
            }
        });

        return $this->audit->finish($batch, $created, 0, $skipped);
    }

    /**
     * Preview a copy into a session that does not exist yet: it is made, previewed and thrown away again.
     *
     * @param string[] $categories
     * @return array{rows: array, problems: string[], total: int, session: ?array, semesters: array<int, array{name: string, start: ?string, end: ?string}>}
     */
    public function previewNew(AcademicSession $source, string $name, array $categories): array
    {
        DB::beginTransaction();
        try {
            $dest = $this->createFrom($source, $name);
            $chosen = array_intersect($categories, array_keys(self::CATEGORIES));
            $out = ($chosen ? $this->preview($source, $dest, $chosen) : ['rows' => [], 'problems' => [], 'total' => 0]) + [
                'session' => ['name' => $dest->name, 'start' => $dest->start_date?->format('d M Y'), 'end' => $dest->end_date?->format('d M Y')],
                'semesters' => $dest->semesters()->get()->map(fn ($s) => ['name' => $s->name, 'start' => $s->start_date?->format('d M Y'), 'end' => $s->end_date?->format('d M Y')])->all(),
            ];
        } catch (RuntimeException $e) {
            $out = ['rows' => [], 'problems' => [$e->getMessage()], 'total' => 0, 'session' => null, 'semesters' => []];
        } finally {
            DB::rollBack();
        }

        return $out;
    }

    /**
     * Make a new session from the source and copy the chosen setup into it, all or nothing.
     *
     * @param string[] $categories
     * @return array{session: AcademicSession, batch: ?OperationBatch}
     */
    public function runNew(AcademicSession $source, string $name, array $categories, User $by): array
    {
        return DB::transaction(function () use ($source, $name, $categories, $by) {
            $dest = $this->createFrom($source, $name, $by);
            $chosen = array_intersect($categories, array_keys(self::CATEGORIES));

            return ['session' => $dest, 'batch' => $chosen ? $this->copy($source, $dest, $chosen, $by, null, false) : null];   // a new session has nothing to save first
        });
    }

    /**
     * Undo a clone. Only what nothing has used since is removed; the rest is left and reported.
     *
     * @return array{batch: OperationBatch, undone: int, kept: string[]}
     */
    public function undo(OperationBatch $original, User $by, ?string $reason = null): array
    {
        if ($original->type !== 'session_clone') {
            throw new RuntimeException('That operation is not a session clone.');
        }
        if ($original->status === 'reversed') {
            throw new RuntimeException('This clone has already been undone.');
        }

        $dest = AcademicSession::findOrFail($original->academic_session_id);
        $reverse = $this->audit->begin('session_clone_undo', 'sessions', $by, $dest->id, $reason, [], $original);
        $undone = 0;
        $kept = [];

        DB::transaction(function () use ($original, $dest, $reverse, &$undone, &$kept) {
            foreach (CloneItem::where('operation_batch_id', $original->id)->where('undone', false)->get() as $item) {
                if ($why = $this->consumed($item, $dest)) {
                    $kept[] = $why;
                    continue;
                }
                $this->revert($item, $reverse);
                $item->update(['undone' => true]);
                $undone++;
            }
        });

        $this->audit->finish($reverse, $undone, 0, count($kept));
        if ($kept) {
            $reverse->update(['status' => 'partial']);
        }
        if (! CloneItem::where('operation_batch_id', $original->id)->where('undone', false)->exists()) {
            $this->audit->markReversed($original);
        }

        return ['batch' => $reverse, 'undone' => $undone, 'kept' => $kept];
    }

    // ---- planning ---------------------------------------------------------------------------------

    private function guard(AcademicSession $source, AcademicSession $dest, array $categories, ?SemesterPair $pair): array
    {
        $problems = [];
        if ($pair && $source->id === $dest->id && $pair->from->id === $pair->to->id) {
            $problems[] = 'Choose two different semesters.';
        } elseif (! $pair && $source->id === $dest->id) {
            $problems[] = 'Choose two different sessions.';
        }
        if (in_array($dest->status, ['closed', 'archived'], true)) {
            $problems[] = "{$dest->name} is {$dest->status}; nothing can be copied into it.";
        }
        if (! array_intersect($categories, array_keys(self::categoriesFor((bool) $pair)))) {
            $problems[] = 'Choose at least one thing to copy.';
        }

        return $problems;
    }

    /** @return array{create: int, skip: int, notes: string[], items: array<int, array>} */
    private function plan(string $category, AcademicSession $source, AcademicSession $dest, ?SemesterPair $pair = null): array
    {
        $years = $this->yearShift($source, $dest);
        $items = [];
        $skip = 0;
        $notes = [];
        // semester type id in the source => semester type id in the destination
        $mapType = fn (?int $id) => $pair ? ($id === $pair->from->id ? $pair->to->id : false) : $id;

        if ($category === 'registration') {
            $have = PeriodSetting::where('academic_session_id', $dest->id)->where('group', 'registration')->get()->keyBy(fn ($p) => (int) $p->semester_type_id);
            $rows = PeriodSetting::where('academic_session_id', $source->id)->where('group', 'registration')->get();
            if ($pair) {
                $rows = $rows->where('semester_type_id', $pair->from->id);
                if ($rows->isEmpty() && ($whole = PeriodSetting::for($source->id, null)->first()) && $whole->values) {
                    $rows = collect([$whole]);   // the semester follows its session: copy what is in force for it
                    $notes[] = "{$pair->from->name} has no settings of its own, so {$source->name}'s are copied to {$dest->name} {$pair->to->name}.";
                }
            }
            foreach ($rows as $row) {
                if (! $row->values) {
                    continue;
                }
                $to = $pair ? $pair->to->id : $row->semester_type_id;
                $existing = $have->get((int) $to);
                if ($existing && $this->valuesPrint($existing->values) === $this->valuesPrint($row->values)) {
                    $skip++;
                    continue;
                }
                if ($existing && $existing->values) {
                    $notes[] = ($existing->label() ?: $dest->name) . ' already has its own registration settings; they will be replaced.';
                }
                $items[] = ['source' => $row->id, 'type' => $to, 'values' => $row->values, 'before' => $existing ? ['values' => $existing->values] : null, 'existing' => $existing?->id];
            }
            if (! $rows->count() && ! $notes) {
                $notes[] = "{$source->name} follows the university's registration settings, so there is nothing of its own to copy.";
            }
        }

        if ($category === 'assessments') {
            $have = AssessmentAssignment::where('academic_session_id', $dest->id)->get()->keyBy(fn ($a) => $a->scope_type . '|' . $a->scope_id);
            foreach (AssessmentAssignment::where('academic_session_id', $source->id)->get() as $a) {
                $mine = $have->get($a->scope_type . '|' . $a->scope_id);
                if ($mine) {
                    $mine->assessment_scheme_id === $a->assessment_scheme_id ? $skip++ : $notes[] = $a->scopeLabel() . ' already has its own scheme in ' . $dest->name . '; it is left as it is.';
                    continue;
                }
                $items[] = ['source' => $a->id, 'assignment' => $a];
            }
        }

        if ($category === 'windows') {
            $targets = $dest->semesters()->get()->keyBy('sort_order');
            $sources = $source->semesters()->get();
            if ($pair) {
                $sources = $sources->where('sort_order', $pair->from->sort_order);
            }
            foreach ($sources as $from) {
                if (! $from->registration_opens_at && ! $from->registration_closes_at) {
                    continue;
                }
                $order = $pair ? $pair->to->sort_order : $from->sort_order;
                $to = $targets->get($order);
                if (! $to) {
                    $notes[] = "{$dest->name} has no semester number {$order}, so the window of {$from->name} is not copied.";
                    continue;
                }
                [$opens, $closes] = [$from->registration_opens_at?->copy(), $from->registration_closes_at?->copy()];
                if ($pair && $from->start_date && $to->start_date) {
                    $days = (int) $from->start_date->diffInDays($to->start_date, false);
                    [$opens, $closes] = [$opens?->addDays($days), $closes?->addDays($days)];
                } elseif ($pair) {
                    $opens = $opens?->addYears($years);
                    $closes = $closes?->addYears($years);
                    if ($from->id !== $to->id && $years === 0) {
                        $notes[] = 'The semesters have no start dates, so the window keeps the same dates; check them after copying.';
                    }
                } else {
                    $opens = $opens?->addYears($years);
                    $closes = $closes?->addYears($years);
                }
                if ($to->registration_opens_at?->equalTo($opens) && $to->registration_closes_at?->equalTo($closes)) {
                    $skip++;
                    continue;
                }
                if ($to->registration_opens_at || $to->registration_closes_at) {
                    $notes[] = "{$to->name} already has a window; it will be replaced.";
                }
                $items[] = ['source' => $from->id, 'dest' => $to->id, 'opens' => $opens, 'closes' => $closes, 'before' => [
                    'registration_opens_at' => $to->registration_opens_at?->toDateTimeString(), 'registration_closes_at' => $to->registration_closes_at?->toDateTimeString(),
                ]];
            }
        }

        if ($category === 'bills') {
            $have = Bill::where('academic_session_id', $dest->id)->get()->map(fn ($b) => $this->billPrint($b))->flip();
            foreach (Bill::where('academic_session_id', $source->id)->get() as $bill) {
                if (($type = $mapType($bill->semester_type_id)) === false) {
                    continue;
                }
                if ($have->has($this->billPrint($bill, $type))) {
                    $skip++;
                    continue;
                }
                $items[] = ['source' => $bill->id, 'bill' => $bill, 'years' => $years, 'type' => $type];
            }
            if ($pair && Bill::where('academic_session_id', $source->id)->whereNull('semester_type_id')->exists()) {
                $notes[] = 'Bills for the whole session are not copied between semesters.';
            }
            if ($items) {
                $notes[] = 'Copied bills start switched off. Review them, then switch them on.';
            }
        }

        if ($category === 'assignments') {
            $have = CourseAssignment::where('academic_session_id', $dest->id)->get()->map(fn ($a) => $this->assignmentPrint($a))->flip();
            foreach (CourseAssignment::where('academic_session_id', $source->id)->get() as $a) {
                if (($type = $mapType($a->semester_type_id)) === false) {
                    continue;
                }
                if ($have->has($a->user_id . '|' . $a->course_id . '|' . $type)) {
                    $skip++;
                    continue;
                }
                $items[] = ['source' => $a->id, 'assignment' => $a, 'type' => $type];
            }
        }

        return ['create' => count($items), 'skip' => $skip, 'notes' => array_values(array_unique($notes)), 'items' => $items];
    }

    private function apply(string $category, array $item, AcademicSession $dest, OperationBatch $batch): void
    {
        if ($category === 'registration') {
            $row = PeriodSetting::updateOrCreate(
                ['academic_session_id' => $dest->id, 'semester_type_id' => $item['type'], 'group' => 'registration'],
                ['values' => $item['values'], 'updated_by' => $batch->user_id],
            );
            CloneItem::create(['operation_batch_id' => $batch->id, 'category' => 'registration', 'source_id' => $item['source'], 'dest_id' => $row->id,
                'before' => $item['before'], 'fingerprint' => $this->valuesPrint($item['values'])]);
            $this->audit->record($batch, 'registration_settings_copied', $row, $item['before'], ['values' => $item['values']]);
        }

        if ($category === 'assessments') {
            /** @var AssessmentAssignment $from */
            $from = $item['assignment'];
            $copy = AssessmentAssignment::create(['assessment_scheme_id' => $from->assessment_scheme_id, 'scope_type' => $from->scope_type, 'scope_id' => $from->scope_id,
                'academic_session_id' => $dest->id, 'assigned_by' => $batch->user_id]);
            CloneItem::create(['operation_batch_id' => $batch->id, 'category' => 'assessments', 'source_id' => $from->id, 'dest_id' => $copy->id]);
            $this->audit->record($batch, 'assessment_choice_copied', $copy, null, ['scheme_id' => $copy->assessment_scheme_id, 'scope' => $from->scopeLabel()]);
        }

        if ($category === 'windows') {
            $semester = Semester::findOrFail($item['dest']);
            $semester->update(['registration_opens_at' => $item['opens'], 'registration_closes_at' => $item['closes']]);
            CloneItem::create(['operation_batch_id' => $batch->id, 'category' => 'windows', 'source_id' => $item['source'], 'dest_id' => $semester->id, 'before' => $item['before'],
                'fingerprint' => $this->windowPrint($item['opens'], $item['closes'])]);
            $this->audit->record($batch, 'window_copied', $semester, $item['before'], ['registration_opens_at' => (string) $item['opens'], 'registration_closes_at' => (string) $item['closes']]);
        }

        if ($category === 'bills') {
            /** @var Bill $from */
            $from = $item['bill'];
            $copy = Bill::create(array_merge($from->only($from->getFillable()), [
                'academic_session_id' => $dest->id, 'is_active' => false, 'semester_type_id' => $item['type'],
                'opens_at' => $from->opens_at?->copy()->addYears($item['years']), 'closes_at' => $from->closes_at?->copy()->addYears($item['years']),
            ]));
            CloneItem::create(['operation_batch_id' => $batch->id, 'category' => 'bills', 'source_id' => $from->id, 'dest_id' => $copy->id, 'fingerprint' => $this->billPrint($copy)]);
            $this->audit->record($batch, 'bill_copied', $copy, null, ['amount' => (string) $copy->amount, 'copied_from' => $from->id]);
        }

        if ($category === 'assignments') {
            /** @var CourseAssignment $from */
            $from = $item['assignment'];
            $copy = CourseAssignment::create(['user_id' => $from->user_id, 'course_id' => $from->course_id, 'semester_type_id' => $item['type'], 'academic_session_id' => $dest->id]);
            CloneItem::create(['operation_batch_id' => $batch->id, 'category' => 'assignments', 'source_id' => $from->id, 'dest_id' => $copy->id]);
            $this->audit->record($batch, 'assignment_copied', $copy, null, ['course_id' => $copy->course_id, 'user_id' => $copy->user_id]);
        }
    }

    // ---- undo -------------------------------------------------------------------------------------

    /**
     * What undoing a clone would do, without doing it.
     *
     * @return array<int, array{item: CloneItem, label: string, why: ?string}>
     */
    public function undoPreview(OperationBatch $original): array
    {
        $dest = AcademicSession::find($original->academic_session_id);
        $out = [];
        foreach (CloneItem::where('operation_batch_id', $original->id)->where('undone', false)->orderBy('id')->get() as $item) {
            $label = match ($item->category) {
                'bills' => 'Bill: ' . (Bill::find($item->dest_id)?->targetLabel() ?? 'removed'),
                'assignments' => 'Course lecturer: ' . (($a = CourseAssignment::with('course', 'user')->find($item->dest_id)) ? $a->course?->code . ' · ' . $a->user?->name : 'removed'),
                'registration' => 'Registration settings: ' . (($p = PeriodSetting::find($item->dest_id)) ? $p->label() : 'removed'),
                'assessments' => 'Assessment scheme: ' . (AssessmentAssignment::find($item->dest_id)?->scopeLabel() ?? 'removed'),
                default => 'Registration window: ' . (Semester::find($item->dest_id)?->name ?? 'semester removed'),
            };
            $out[] = ['item' => $item, 'label' => $label, 'why' => $dest ? $this->consumed($item, $dest) : 'The session no longer exists.'];
        }

        return $out;
    }

    /** Why this copy cannot be removed, or null when nothing has used it. */
    private function consumed(CloneItem $item, AcademicSession $dest): ?string
    {
        if ($item->category === 'bills') {
            $bill = Bill::find($item->dest_id);
            if (! $bill) {
                return null;
            }
            if ($bill->is_active) {
                return "A copied bill (" . $bill->targetLabel() . ") has been switched on, so it was kept.";
            }
            if (Invoice::where('academic_session_id', $dest->id)->exists()) {
                return 'Invoices already exist for ' . $dest->name . ', so the copied bills were kept.';
            }
        }
        if ($item->category === 'assignments') {
            $a = CourseAssignment::find($item->dest_id);
            if ($a && ResultSheet::where('course_id', $a->course_id)->where('academic_session_id', $dest->id)->where('semester_type_id', $a->semester_type_id)->exists()) {
                return 'A score sheet already exists for a copied lecturer assignment, so it was kept.';
            }
        }
        if ($item->category === 'registration') {
            $p = PeriodSetting::find($item->dest_id);
            if ($p && $this->valuesPrint($p->values) !== $item->fingerprint) {
                return 'Registration settings for ' . $p->label() . ' were changed after the copy, so they were left as they are.';
            }
        }
        if ($item->category === 'assessments' && AssessmentAssignment::whereKey($item->dest_id)->exists()
            && ResultSheet::where('academic_session_id', $dest->id)->exists()) {
            return 'Score sheets already exist in ' . $dest->name . ', so the copied assessment schemes were kept.';
        }
        if ($item->category === 'windows') {
            $s = Semester::find($item->dest_id);
            if ($s && $this->windowPrint($s->registration_opens_at, $s->registration_closes_at) !== $item->fingerprint) {
                return "{$s->name}'s registration window was changed after the clone, so it was left as it is.";
            }
        }

        return null;
    }

    private function revert(CloneItem $item, OperationBatch $reverse): void
    {
        if ($item->category === 'registration' && ($p = PeriodSetting::find($item->dest_id))) {
            $this->audit->record($reverse, 'registration_settings_restored', $p, ['values' => $p->values], $item->before);
            $item->before ? $p->update(['values' => $item->before['values']]) : $p->delete();
        }
        if ($item->category === 'assessments' && ($a = AssessmentAssignment::find($item->dest_id))) {
            $this->audit->record($reverse, 'assessment_choice_removed', $a, ['scheme_id' => $a->assessment_scheme_id]);
            $a->delete();
        }
        if ($item->category === 'bills' && ($bill = Bill::find($item->dest_id))) {
            $this->audit->record($reverse, 'bill_removed', $bill, ['amount' => (string) $bill->amount], null, null);
            $bill->delete();
        }
        if ($item->category === 'assignments' && ($a = CourseAssignment::find($item->dest_id))) {
            $this->audit->record($reverse, 'assignment_removed', $a, ['course_id' => $a->course_id, 'user_id' => $a->user_id]);
            $a->delete();
        }
        if ($item->category === 'windows' && ($s = Semester::find($item->dest_id))) {
            $this->audit->record($reverse, 'window_restored', $s, null, $item->before);
            $s->update($item->before ?? ['registration_opens_at' => null, 'registration_closes_at' => null]);
        }
    }

    // ---- fingerprints -----------------------------------------------------------------------------

    private function billPrint(Bill $b, int|null|false $type = false): string
    {
        return implode('|', [$b->faculty_id, $b->department_id, $b->study_category_id, $b->programme_id, $b->level, $type === false ? $b->semester_type_id : $type, $b->bill_item_id, $b->student_type, $b->nationality, number_format((float) $b->amount, 2, '.', '')]);
    }

    private function assignmentPrint(CourseAssignment $a): string
    {
        return $a->user_id . '|' . $a->course_id . '|' . $a->semester_type_id;
    }

    private function valuesPrint(?array $values): string
    {
        $values = array_map('strval', $values ?? []);
        ksort($values);

        return json_encode($values);
    }

    private function windowPrint($opens, $closes): string
    {
        return ($opens?->format('Y-m-d H:i') ?? '-') . '>' . ($closes?->format('Y-m-d H:i') ?? '-');
    }

    /** How many years later the destination session is than the source, from names like 2025/2026. */
    private function yearShift(AcademicSession $source, AcademicSession $dest): int
    {
        return $this->yearsBetween($source->name, $dest->name);
    }

    private function yearsBetween(string $from, string $to): int
    {
        $a = (int) substr($from, 0, 4);
        $b = (int) substr($to, 0, 4);

        return ($a && $b) ? $b - $a : 1;
    }
}
