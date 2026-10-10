<?php

namespace App\Services;

use App\Models\CourseResult;
use App\Models\ResultComplaint;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Students querying a published result, and staff answering (and, when upheld, correcting the score). */
class ResultComplaintService
{
    public function __construct(private ResultService $results)
    {
    }

    /** Days after publishing during which a result can be queried; 0 = no limit. */
    public static function windowDays(): int
    {
        return (int) Setting::get('results.complaint_days', '14');
    }

    /** Why this student cannot query this result now, or null if they can. */
    public function blocker(Student $student, CourseResult $result): ?string
    {
        if ($result->student_id !== $student->id || $result->status !== 'published') {
            return 'You can only query your own published results.';
        }
        if (ResultComplaint::where('course_result_id', $result->id)->where('status', 'open')->exists()) {
            return 'You already have a query on this result awaiting review.';
        }
        $days = self::windowDays();
        $published = $result->sheet?->published_at ?? $result->updated_at;
        if ($days > 0 && $published && $published->copy()->addDays($days)->isPast()) {
            return "Results can only be queried within {$days} days of being published.";
        }

        return null;
    }

    public function lodge(Student $student, CourseResult $result, string $reason): ResultComplaint
    {
        if ($why = $this->blocker($student, $result)) {
            throw new RuntimeException($why);
        }
        if (blank($reason)) {
            throw new RuntimeException('Say what you think is wrong with this result.');
        }

        return ResultComplaint::create(['student_id' => $student->id, 'course_result_id' => $result->id, 'reason' => trim($reason)]);
    }

    /**
     * Answer a complaint. When it is upheld and new scores are given, the published score is corrected
     * (needs "Amend published results") and the correction is logged on the sheet.
     */
    public function respond(ResultComplaint $complaint, User $user, string $decision, string $response, $ca = null, $exam = null): ResultComplaint
    {
        if ($complaint->status !== 'open') {
            throw new RuntimeException('This query has already been answered.');
        }
        if (! in_array($decision, ['upheld', 'rejected'], true)) {
            throw new RuntimeException('Choose whether the query is upheld.');
        }
        if (blank($response)) {
            throw new RuntimeException('Write the answer the student will see.');
        }
        $correct = $decision === 'upheld' && ! blank($ca) && ! blank($exam);
        if ($correct && ! $user->can('Amend published results')) {
            throw new RuntimeException('You are not allowed to correct published scores. Answer the query and ask someone who can to correct it.');
        }

        DB::transaction(function () use ($complaint, $user, $decision, $response, $correct, $ca, $exam) {
            if ($correct) {
                $this->results->amend($complaint->result, $ca, $exam, 'Result query upheld: ' . $response, $user);
            }
            $complaint->update(['status' => $decision, 'response' => trim($response), 'handled_by' => $user->id, 'handled_at' => now()]);
            app(OperationAudit::class)->note('complaint_respond', 'results', $user, 'query_answered', $complaint, ['status' => 'open'], ['status' => $decision, 'response' => trim($response), 'score_corrected' => $correct], null, $complaint->result?->academic_session_id);
        });
        app(DocumentMailer::class)->complaintAnswered($complaint->fresh(), $user);

        return $complaint;
    }
}
