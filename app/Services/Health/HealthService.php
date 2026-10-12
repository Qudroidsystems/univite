<?php

namespace App\Services\Health;

use App\Data\Health\ClinicStats;
use App\Data\Health\Examination;
use App\Data\Health\HealthDetails;
use App\Data\Health\VisitInput;
use App\Models\AcademicSession;
use App\Models\ClinicVisit;
use App\Models\HealthAccessLog;
use App\Models\HealthRecord;
use App\Models\Student;
use App\Models\User;
use App\Services\ClearanceService;
use App\Services\Messenger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Health records, medical examinations and clinic visits. Clinical notes are encrypted, and every time clinic staff open
 * or change someone's health information it is written to the access log, which the person can see.
 */
class HealthService
{
    public function __construct(private ClearanceService $clearance, private Messenger $messenger)
    {
    }

    public function record(User $user): HealthRecord
    {
        return HealthRecord::firstOrNew(['user_id' => $user->id]);
    }

    public function logAccess(User $subject, ?User $viewer, string $action, ?string $detail = null, ?string $ip = null): void
    {
        HealthAccessLog::create(['user_id' => $subject->id, 'viewer_id' => $viewer?->id, 'action' => $action, 'detail' => $detail, 'ip' => $ip]);
    }

    /** The person updates their own details. */
    public function updateOwn(User $user, HealthDetails $d): HealthRecord
    {
        $record = $this->record($user);
        $record->fill($d->attributes() + ['self_updated_at' => now()])->save();

        return $record;
    }

    /** Clinic staff correct or complete someone's details. */
    public function updateByClinic(User $subject, HealthDetails $d, User $by, ?string $ip = null): HealthRecord
    {
        $record = $this->record($subject);
        $record->fill($d->attributes())->save();
        $this->logAccess($subject, $by, 'updated', 'Health details', $ip);

        return $record;
    }

    public function examine(User $subject, Examination $e, User $by, ?string $ip = null): HealthRecord
    {
        $record = DB::transaction(function () use ($subject, $e, $by, $ip) {
            $record = $this->record($subject);
            $record->fill(array_filter(['blood_group' => $e->bloodGroup, 'genotype' => $e->genotype, 'height_cm' => $e->heightCm, 'weight_kg' => $e->weightKg], fn ($v) => $v !== null) + [
                'fitness' => $e->fitness, 'fitness_note' => $e->note, 'examined_at' => now(), 'examined_by' => $by->id, 'exam_session_id' => AcademicSession::current()?->id,
            ])->save();
            $this->logAccess($subject, $by, 'examined', HealthRecord::FITNESS[$e->fitness], $ip);

            return $record;
        });
        if ($subject->student) {
            $this->clearance->refresh($subject->student);
        }

        return $record;
    }

    public function recordVisit(User $subject, VisitInput $v, User $by, ?string $ip = null): ClinicVisit
    {
        $visit = DB::transaction(function () use ($subject, $v, $by, $ip) {
            $from = $v->restDays > 0 ? $v->seenAt->startOfDay() : null;
            $visit = ClinicVisit::create([
                'user_id' => $subject->id, 'seen_at' => $v->seenAt, 'category' => $v->category, 'complaint' => $v->complaint, 'findings' => $v->findings,
                'diagnosis' => $v->diagnosis, 'treatment' => $v->treatment, 'outcome' => $v->outcome, 'referred_to' => $v->outcome === 'referred' ? $v->referredTo : null,
                'rest_days' => $v->restDays, 'excused_from' => $from, 'excused_to' => $from?->addDays($v->restDays - 1),
                'excuse_token' => $from ? Str::random(40) : null, 'seen_by' => $by->id,
            ]);
            $this->logAccess($subject, $by, 'visit_recorded', ClinicVisit::CATEGORIES[$v->category], $ip);

            return $visit;
        });
        if ($visit->hasExcuse()) {
            $this->messenger->notify($subject, 'health', 'Excused from duties', 'The health centre excused you from ' . $visit->excused_from->format('j M') . ' to ' . $visit->excused_to->format('j M Y') . '. Show the excuse note to your lecturers or supervisor.',
                $visit, route('my.health'), 'info', null, ['portal']);
        }

        return $visit;
    }

    /** Counts for a period: no names and no clinical notes. */
    public function stats(CarbonImmutable $from, CarbonImmutable $to): ClinicStats
    {
        $visits = ClinicVisit::whereBetween('seen_at', [$from->startOfDay(), $to->endOfDay()]);
        $userIds = (clone $visits)->distinct()->pluck('user_id');
        $studentIds = Student::whereIn('user_id', $userIds)->pluck('user_id');
        $byWeek = (clone $visits)->get(['seen_at'])->groupBy(fn ($v) => $v->seen_at->startOfWeek()->toDateString())->map->count()->sortKeys()->all();
        $session = AcademicSession::current();
        $newIds = $session ? Student::where('academic_session_id', $session->id)->pluck('user_id') : collect();

        return new ClinicStats(
            (clone $visits)->count(), $userIds->count(), $studentIds->count(), $userIds->count() - $studentIds->count(),
            (clone $visits)->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category')->map(fn ($n) => (int) $n)->all(),
            (clone $visits)->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome')->map(fn ($n) => (int) $n)->all(),
            $byWeek, (int) (clone $visits)->sum('rest_days'),
            $newIds->count(), HealthRecord::whereIn('user_id', $newIds)->whereNotNull('examined_at')->count(),
            HealthRecord::whereIn('user_id', $newIds)->whereNotNull('fitness')->selectRaw('fitness, count(*) as n')->groupBy('fitness')->pluck('n', 'fitness')->map(fn ($n) => (int) $n)->all(),
        );
    }
}
