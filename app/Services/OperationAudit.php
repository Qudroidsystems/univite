<?php

namespace App\Services;

use App\Models\OperationBatch;
use App\Models\OperationEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The operation log. Every sensitive bulk action opens a batch with a short operation ID, records append-only events,
 * and is closed with its counts. Reversals are new batches linked to the original; nothing is ever edited.
 */
class OperationAudit
{
    /** Fields that must never be written to the log, even by mistake. */
    private const SECRET = ['password', 'password_confirmation', 'remember_token', 'nin', 'token', 'api_key', 'secret'];

    public function begin(string $type, string $module, ?User $by, ?int $sessionId = null, ?string $reason = null, array $meta = [], ?OperationBatch $relatedTo = null): OperationBatch
    {
        do {
            $id = 'OP-' . strtoupper(Str::random(8));
        } while (OperationBatch::where('operation_id', $id)->exists());

        return OperationBatch::create([
            'operation_id' => $id, 'type' => $type, 'module' => $module, 'user_id' => $by?->id, 'academic_session_id' => $sessionId,
            'status' => 'completed', 'reason' => $reason, 'meta' => $this->clean($meta) ?: null, 'related_operation_id' => $relatedTo?->id,
        ]);
    }

    public function record(OperationBatch $batch, string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?string $note = null, ?int $corrects = null, ?string $label = null): OperationEvent
    {
        return OperationEvent::create([
            'operation_batch_id' => $batch->id, 'user_id' => $batch->user_id, 'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null, 'subject_id' => $subject?->getKey(),
            'label' => Str::limit($label ?? ($subject ? self::labelFor($subject) : ''), 185, '') ?: null,
            'before' => $before !== null ? $this->clean($before) : null, 'after' => $after !== null ? $this->clean($after) : null,
            'note' => $note ? Str::limit($note, 500, '') : null, 'corrects_event_id' => $corrects,
        ]);
    }

    public function finish(OperationBatch $batch, int $success, int $failed = 0, int $skipped = 0): OperationBatch
    {
        $status = $failed > 0 ? ($success > 0 ? 'partial' : 'failed') : 'completed';
        $batch->update(['success_count' => $success, 'failed_count' => $failed, 'skipped_count' => $skipped, 'status' => $status]);

        return $batch;
    }

    /** Mark an operation as reversed by another. The original's events stay exactly as they were. */
    public function markReversed(OperationBatch $original): void
    {
        $original->update(['status' => 'reversed']);
    }

    /** One-line operations that need no counts (a publish, a lifecycle change). */
    public function note(string $type, string $module, ?User $by, string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?string $reason = null, ?int $sessionId = null): OperationBatch
    {
        $batch = $this->begin($type, $module, $by, $sessionId, $reason);
        $this->record($batch, $action, $subject, $before, $after, $reason);

        return $this->finish($batch, 1);
    }

    /** A readable name for an affected record, kept on the event so it survives later renames. */
    public static function labelFor(Model $m): string
    {
        return match (true) {
            $m instanceof \App\Models\Student => trim($m->full_name . ($m->matric_number ? ' · ' . $m->matric_number : '')),
            $m instanceof \App\Models\Payment => $m->reference . ' · ₦' . number_format(abs((float) $m->amount), 2),
            $m instanceof \App\Models\Invoice => $m->reference . ' · ' . $m->title,
            $m instanceof \App\Models\CourseResult => trim(($m->student?->full_name ?? 'Student') . ' · ' . ($m->course?->code ?? '')),
            $m instanceof \App\Models\ResultSheet => trim(($m->course?->code ?? 'Sheet') . ' · ' . ($m->session?->name ?? '')),
            $m instanceof \App\Models\GraduationList => 'Graduation list · ' . ($m->programme?->name ?? '') . ' · ' . ($m->session?->name ?? ''),
            $m instanceof \App\Models\StandingWaiver => trim(($m->student?->full_name ?? 'Student') . ' · allowed to register'),
            $m instanceof \App\Models\ResultComplaint => trim(($m->student?->full_name ?? 'Student') . ' · result query'),
            $m instanceof \App\Models\RegistrationForm => trim(($m->student?->full_name ?? 'Student') . ' · course form'),
            isset($m->name) && is_string($m->name) => $m->name,
            default => class_basename($m) . ' #' . $m->getKey(),
        };
    }

    private function clean(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::SECRET, true)) {
                continue;
            }
            $out[$key] = is_array($value) ? $this->clean($value) : $value;
        }

        return $out;
    }
}
