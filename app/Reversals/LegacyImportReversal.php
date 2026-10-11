<?php

namespace App\Reversals;

use App\Models\CourseResult;
use App\Models\LegacyImport;
use App\Models\OperationBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Undo a legacy import: remove the results or opening balances it created. Anything changed after the import (a
 * corrected result) is left alone and listed. Sessions and courses the import added stay, as other records may use them.
 */
class LegacyImportReversal extends BaseReversal
{
    public function title(OperationBatch $op): string
    {
        return 'Undo this legacy results import (' . ($op->meta['file'] ?? 'file') . ')';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $user->can('Import legacy results');
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('reverse', ['reverse' => ['Take back the import', 'Remove every result or previous total this import added. Sessions and courses it added stay.']]);
        $import = $this->subject($op, LegacyImport::class);
        if (! $import || $import->status !== 'imported') {
            $plan->blockers[] = 'This import is no longer in place.';

            return $plan;
        }
        $cut = $import->imported_at->copy()->addSeconds(5);
        if ($import->kind === 'balances') {
            foreach ($import->balances()->with('student')->get() as $b) {
                $b->updated_at->gt($cut)
                    ? $plan->skip($b->student?->matric_number ?? 'Student', "TNU {$b->tnu}, TCP {$b->tcp}", 'Changed after the import; left as it is.', $b->id)
                    : $plan->change($b->student?->matric_number ?? 'Student', "TNU {$b->tnu}, TCP {$b->tcp}", 'Removed', $b->id);
            }
        } else {
            // One line per student keeps the preview readable for imports of many thousands of results.
            foreach ($import->results()->with('student')->get()->groupBy('student_id') as $rows) {
                $changed = $rows->filter(fn ($r) => $r->updated_at->gt($cut));
                $label = $rows->first()->student?->matric_number ?? 'Student';
                if ($changed->count() === $rows->count()) {
                    $plan->skip($label, $rows->count() . ' result(s)', 'Corrected after the import; left as they are.', $rows->first()->student_id);
                } else {
                    $plan->change($label, $rows->count() . ' result(s)', 'Removed' . ($changed->isNotEmpty() ? ' (except ' . $changed->count() . ' corrected since)' : ''), $rows->first()->student_id);
                }
            }
        }
        if ($import->operation?->events()->whereIn('action', ['session added', 'course added'])->exists()) {
            $plan->warnings[] = 'Sessions and courses this import added are kept.';
        }
        $plan->outcome = 'The imported records are removed and every affected CGPA is worked out again without them. The file can be imported again afterwards.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        $new = $this->begin($op, 'legacy_import_undo', $by, $reason);
        $import = $this->subject($op, LegacyImport::class);
        $cut = $import->imported_at->copy()->addSeconds(5);
        $n = 0;
        DB::transaction(function () use ($plan, $import, $cut, $new, $reason, &$n) {
            $keys = array_column($plan->changes(), 'key');
            if ($import->kind === 'balances') {
                $n = $import->balances()->whereIn('id', $keys)->where('updated_at', '<=', $cut)->delete();
            } else {
                $n = CourseResult::where('legacy_import_id', $import->id)->whereIn('student_id', $keys)->where('updated_at', '<=', $cut)->delete();
            }
            $import->update(['status' => 'undone']);
            $this->audit->record($new, 'import undone', $import, ['status' => 'imported', 'records' => $import->imported], ['status' => 'undone', 'removed' => $n], $reason, null, $import->file_name);
        });
        $c = $plan->counts();
        $this->audit->finish($new, $n, 0, $c['skip']);
        $this->audit->markReversed($op);

        return $new;
    }
}
