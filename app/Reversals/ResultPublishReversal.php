<?php

namespace App\Reversals;

use App\Models\OperationBatch;
use App\Models\ResultComplaint;
use App\Models\ResultSheet;
use App\Models\User;
use App\Services\DocumentMailer;
use App\Services\ResultService;

/**
 * Formally withdraw a published sheet (for example when Senate sets it aside). The scores are not changed or removed:
 * the sheet goes back to "approved", stops counting in GPAs, and can be published again. Not allowed once any student
 * on it has graduated.
 */
class ResultPublishReversal extends BaseReversal
{
    public function title(OperationBatch $op): string
    {
        return 'Withdraw this published result';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $user->can('Withdraw published results');
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('withdraw', ['withdraw' => ['Withdraw', 'Take the sheet back to approved. Scores are kept; students stop seeing them until the sheet is published again.']]);
        $sheet = $this->subject($op, ResultSheet::class)?->load('course', 'results.student');
        if (! $sheet) {
            $plan->blockers[] = 'The score sheet is no longer on record.';

            return $plan;
        }
        if ($sheet->status !== 'published') {
            $plan->blockers[] = 'This sheet is no longer published (it is ' . $sheet->status . ').';

            return $plan;
        }
        $graduated = 0;
        foreach ($sheet->results as $r) {
            $label = ($r->student?->full_name ?? 'Student') . ' · ' . ($r->student?->matric_number ?? '');
            if ($r->student?->graduated_at) {
                $plan->block($label, "{$r->total} ({$r->grade})", 'The student has graduated with this result.', $r->id);
                $graduated++;
            } else {
                $plan->change($label, "{$r->total} ({$r->grade}) published", 'Withdrawn (kept, not counted)', $r->id);
            }
        }
        if ($graduated) {
            $plan->blockers[] = "{$graduated} student(s) on this sheet have graduated. Reverse their graduation first, or correct individual scores instead.";
        }
        $open = ResultComplaint::whereIn('course_result_id', $sheet->results->pluck('id'))->where('status', 'open')->count();
        if ($open) {
            $plan->warnings[] = "{$open} result quer" . ($open > 1 ? 'ies are' : 'y is') . ' still open on this sheet.';
        }
        $plan->warnings[] = 'Course forms and carry-overs already worked out from this result are not changed.';
        $plan->outcome = $sheet->course?->code . ' goes back to "approved". Every student on it is emailed that the result was withdrawn for review.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        $sheet = $this->subject($op, ResultSheet::class);
        $new = $this->begin($op, 'result_withdraw', $by, $reason, ['course' => $sheet->course?->code]);
        app(ResultService::class)->withdraw($sheet, $by, $reason);
        $this->audit->record($new, 'sheet_withdrawn', $sheet, ['status' => 'published'], ['status' => 'approved'], $reason);
        $results = $sheet->results()->with('student')->get();
        foreach ($results as $r) {
            $this->audit->record($new, 'result_withdrawn', $r, ['status' => 'published', 'total' => $r->total, 'grade' => $r->grade], ['status' => 'approved']);
        }
        $this->audit->finish($new, $results->count());
        $this->audit->markReversed($op);
        $mailer = app(DocumentMailer::class);
        $results->each(fn ($r) => $mailer->resultNotice($r, 'withdrawn', null, $reason, $by));

        return $new;
    }
}
