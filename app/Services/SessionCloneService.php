<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Bill;
use App\Models\CloneItem;
use App\Models\CourseAssignment;
use App\Models\Invoice;
use App\Models\OperationBatch;
use App\Models\ResultSheet;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copy a session's configuration into another session. Only setup is copied: never registrations, scores, results,
 * payments, invoices or any student data. The source is read, never written. Running it twice does nothing the second time.
 */
class SessionCloneService
{
    public const CATEGORIES = [
        'windows'     => ['Registration windows', 'Copies each semester\'s registration open/close dates into the matching semester of the new session, moved forward by the year difference.'],
        'bills'       => ['School bills (fee schedule)', 'Copies the fee schedule as inactive bills so they can be reviewed and switched on. Payment windows move forward by the year difference.'],
        'assignments' => ['Course lecturers', 'Copies which lecturer takes which course each semester.'],
    ];

    public function __construct(private OperationAudit $audit)
    {
    }

    /**
     * What a clone would do, without doing anything.
     *
     * @param string[] $categories
     * @return array{rows: array<string, array{create: int, skip: int, notes: string[]}>, problems: string[], total: int}
     */
    public function preview(AcademicSession $source, AcademicSession $dest, array $categories): array
    {
        $problems = $this->guard($source, $dest, $categories);
        $rows = [];
        foreach ($categories as $category) {
            if (isset(self::CATEGORIES[$category]) && ! $problems) {
                $rows[$category] = $this->plan($category, $source, $dest);
            }
        }

        return ['rows' => $rows, 'problems' => $problems, 'total' => (int) collect($rows)->sum('create')];
    }

    /** @param string[] $categories */
    public function run(AcademicSession $source, AcademicSession $dest, array $categories, User $by): OperationBatch
    {
        $preview = $this->preview($source, $dest, $categories);
        if ($preview['problems']) {
            throw new RuntimeException(implode(' ', $preview['problems']));
        }

        $batch = $this->audit->begin('session_clone', 'sessions', $by, $dest->id, null, ['source_session' => $source->name, 'destination_session' => $dest->name, 'categories' => array_values($categories)]);
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($preview, $source, $dest, $batch, &$created, &$skipped) {
            foreach (array_keys($preview['rows']) as $category) {
                $plan = $this->plan($category, $source, $dest);
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

    private function guard(AcademicSession $source, AcademicSession $dest, array $categories): array
    {
        $problems = [];
        if ($source->id === $dest->id) {
            $problems[] = 'Choose two different sessions.';
        }
        if (in_array($dest->status, ['closed', 'archived'], true)) {
            $problems[] = "{$dest->name} is {$dest->status}; nothing can be copied into it.";
        }
        if (! array_intersect($categories, array_keys(self::CATEGORIES))) {
            $problems[] = 'Choose at least one thing to copy.';
        }

        return $problems;
    }

    /** @return array{create: int, skip: int, notes: string[], items: array<int, array>} */
    private function plan(string $category, AcademicSession $source, AcademicSession $dest): array
    {
        $years = $this->yearShift($source, $dest);
        $items = [];
        $skip = 0;
        $notes = [];

        if ($category === 'windows') {
            $targets = $dest->semesters()->get()->keyBy('sort_order');
            foreach ($source->semesters()->get() as $from) {
                if (! $from->registration_opens_at && ! $from->registration_closes_at) {
                    continue;
                }
                $to = $targets->get($from->sort_order);
                if (! $to) {
                    $notes[] = "{$dest->name} has no semester number {$from->sort_order} ({$from->name}), so its window is not copied.";
                    continue;
                }
                $opens = $from->registration_opens_at?->copy()->addYears($years);
                $closes = $from->registration_closes_at?->copy()->addYears($years);
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
                if ($have->has($this->billPrint($bill))) {
                    $skip++;
                    continue;
                }
                $items[] = ['source' => $bill->id, 'bill' => $bill, 'years' => $years];
            }
            if ($items) {
                $notes[] = 'Copied bills start switched off. Review them, then switch them on.';
            }
        }

        if ($category === 'assignments') {
            $have = CourseAssignment::where('academic_session_id', $dest->id)->get()->map(fn ($a) => $this->assignmentPrint($a))->flip();
            foreach (CourseAssignment::where('academic_session_id', $source->id)->get() as $a) {
                if ($have->has($this->assignmentPrint($a))) {
                    $skip++;
                    continue;
                }
                $items[] = ['source' => $a->id, 'assignment' => $a];
            }
        }

        return ['create' => count($items), 'skip' => $skip, 'notes' => array_values(array_unique($notes)), 'items' => $items];
    }

    private function apply(string $category, array $item, AcademicSession $dest, OperationBatch $batch): void
    {
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
                'academic_session_id' => $dest->id, 'is_active' => false,
                'opens_at' => $from->opens_at?->copy()->addYears($item['years']), 'closes_at' => $from->closes_at?->copy()->addYears($item['years']),
            ]));
            CloneItem::create(['operation_batch_id' => $batch->id, 'category' => 'bills', 'source_id' => $from->id, 'dest_id' => $copy->id, 'fingerprint' => $this->billPrint($copy)]);
            $this->audit->record($batch, 'bill_copied', $copy, null, ['amount' => (string) $copy->amount, 'copied_from' => $from->id]);
        }

        if ($category === 'assignments') {
            /** @var CourseAssignment $from */
            $from = $item['assignment'];
            $copy = CourseAssignment::create(['user_id' => $from->user_id, 'course_id' => $from->course_id, 'semester_type_id' => $from->semester_type_id, 'academic_session_id' => $dest->id]);
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

    private function billPrint(Bill $b): string
    {
        return implode('|', [$b->faculty_id, $b->department_id, $b->study_category_id, $b->programme_id, $b->level, $b->semester_type_id, $b->bill_item_id, $b->student_type, $b->nationality, number_format((float) $b->amount, 2, '.', '')]);
    }

    private function assignmentPrint(CourseAssignment $a): string
    {
        return $a->user_id . '|' . $a->course_id . '|' . $a->semester_type_id;
    }

    private function windowPrint($opens, $closes): string
    {
        return ($opens?->format('Y-m-d H:i') ?? '-') . '>' . ($closes?->format('Y-m-d H:i') ?? '-');
    }

    /** How many years later the destination session is than the source, from names like 2025/2026. */
    private function yearShift(AcademicSession $source, AcademicSession $dest): int
    {
        $a = (int) substr($source->name, 0, 4);
        $b = (int) substr($dest->name, 0, 4);

        return ($a && $b) ? $b - $a : 1;
    }
}
