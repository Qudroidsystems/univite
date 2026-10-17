<?php

namespace App\Services\Setup;

use App\Data\Setup\RestorePlan;
use App\Models\AcademicSession;
use App\Models\OperationBatch;
use App\Models\SetupSnapshot;
use App\Models\User;
use App\Services\OperationAudit;
use App\Services\Setup\Sections\AcademicRulesSection;
use App\Services\Setup\Sections\ApprovalStepsSection;
use App\Services\Setup\Sections\AssessmentsSection;
use App\Services\Setup\Sections\BillsSection;
use App\Services\Setup\Sections\GradingSection;
use App\Services\Setup\Sections\LecturersSection;
use App\Services\Setup\Sections\RegistrationSection;
use App\Services\Setup\Sections\WindowsSection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Snapshots of the setup: take one (by hand, or automatically before a copy or restore changes anything), see what
 * restoring it would change, and restore some or all of its sections. Restoring first takes an automatic snapshot of
 * what is there, so a restore can itself be undone by restoring that one. Student data, scores, results and payments
 * are never part of a snapshot.
 */
class SetupSnapshots
{
    /** Automatic snapshots kept; older ones are removed as new ones are taken. Manual ones are kept until deleted. */
    public const KEEP_AUTO = 40;

    /** Remix icon for each section (also used for the matching copy categories). */
    public const ICONS = [
        'registration' => 'ri-list-settings-line', 'windows' => 'ri-calendar-check-line', 'bills' => 'ri-bill-line', 'assignments' => 'ri-user-star-line',
        'assessments' => 'ri-file-list-3-line', 'rules' => 'ri-scales-3-line', 'grading' => 'ri-medal-line', 'approval' => 'ri-git-branch-line',
    ];

    /** @var array<string, SetupSection> */
    private array $sections;

    public function __construct(
        private OperationAudit $audit,
        RegistrationSection $registration, WindowsSection $windows, BillsSection $bills, LecturersSection $lecturers,
        AssessmentsSection $assessments, AcademicRulesSection $rules, GradingSection $grading, ApprovalStepsSection $approval,
    ) {
        foreach ([$registration, $windows, $bills, $lecturers, $assessments, $rules, $grading, $approval] as $s) {
            $this->sections[$s->key()] = $s;
        }
    }

    /** @return array<string, SetupSection> */
    public function sections(): array
    {
        return $this->sections;
    }

    /** Sections that can be snapshotted with (or without) a session. @return array<string, SetupSection> */
    public function available(?AcademicSession $session): array
    {
        return array_filter($this->sections, fn (SetupSection $s) => $session || ! $s->perSession());
    }

    /** @param  string[]  $keys */
    public function take(string $label, ?AcademicSession $session, array $keys, ?User $by, string $kind = 'manual', ?string $note = null): SetupSnapshot
    {
        $keys = array_values(array_intersect(array_keys($this->available($session)), $keys));
        if (! $keys) {
            throw new RuntimeException('Choose at least one part of the setup to save.');
        }
        $payload = $summary = [];
        foreach ($keys as $key) {
            $payload[$key] = $this->sections[$key]->capture($session);
            $summary[$key] = $this->sections[$key]->count($payload[$key]);
        }

        $snap = SetupSnapshot::create([
            'label' => mb_substr($label, 0, 150), 'kind' => $kind, 'academic_session_id' => $session?->id, 'sections' => $keys,
            'payload' => json_encode($payload), 'summary' => $summary, 'note' => $note, 'created_by' => $by?->id,
        ]);
        if ($kind === 'auto') {
            SetupSnapshot::where('kind', 'auto')->orderByDesc('id')->skip(self::KEEP_AUTO)->take(PHP_INT_MAX)->pluck('id')
                ->whenNotEmpty(fn ($ids) => SetupSnapshot::whereIn('id', $ids)->delete());
        }

        return $snap;
    }

    /**
     * What restoring these sections would change, without changing anything.
     *
     * @param  string[]  $keys
     * @return array<string, RestorePlan>
     */
    public function preview(SetupSnapshot $snap, array $keys): array
    {
        $data = $snap->data();
        $session = $snap->session;
        $this->guard($snap, $session);
        $out = [];
        foreach ($this->chosen($snap, $keys) as $key) {
            $out[$key] = $this->sections[$key]->restore($data[$key] ?? [], $session, false);
        }

        return $out;
    }

    /**
     * Restore these sections of a snapshot.
     *
     * @param  string[]  $keys
     * @return array{batch: OperationBatch, plans: array<string, RestorePlan>, before: SetupSnapshot}
     */
    public function restore(SetupSnapshot $snap, array $keys, User $by, ?string $reason = null): array
    {
        $session = $snap->session;
        $this->guard($snap, $session);
        $keys = $this->chosen($snap, $keys);
        if (! $keys) {
            throw new RuntimeException('Choose at least one part of the snapshot to restore.');
        }

        $before = $this->take('Before restoring “' . $snap->label . '”', $session, $keys, $by, 'auto');
        $batch = $this->audit->begin('snapshot_restore', 'sessions', $by, $session?->id, $reason, [
            'snapshot' => $snap->label, 'snapshot_id' => $snap->id, 'saved_first_as' => $before->id, 'sections' => $keys,
        ]);
        $data = $snap->data();
        $plans = [];

        DB::transaction(function () use ($keys, $data, $session, $batch, &$plans) {
            foreach ($keys as $key) {
                $plans[$key] = $this->sections[$key]->restore($data[$key] ?? [], $session, true);
                $this->audit->record($batch, 'section_restored', null, null, [
                    'section' => $this->sections[$key]->label(), 'result' => $plans[$key]->summary(), 'kept' => $plans[$key]->kept,
                ], null, null, $this->sections[$key]->label());
            }
        });

        $changed = (int) array_sum(array_map(fn (RestorePlan $p) => $p->changes(), $plans));
        $this->audit->finish($batch, $changed, 0, (int) array_sum(array_map(fn (RestorePlan $p) => count($p->kept), $plans)));

        return ['batch' => $batch->fresh(), 'plans' => $plans, 'before' => $before];
    }

    private function guard(SetupSnapshot $snap, ?AcademicSession $session): void
    {
        if ($snap->academic_session_id && ! $session) {
            throw new RuntimeException('The session this snapshot belongs to has been deleted, so it cannot be restored.');
        }
        if ($session && in_array($session->status, ['closed', 'archived'], true)) {
            throw new RuntimeException("{$session->name} is {$session->status}; reopen it before restoring its setup.");
        }
    }

    /** @return string[] */
    private function chosen(SetupSnapshot $snap, array $keys): array
    {
        return array_values(array_intersect($snap->sections ?? [], $keys, array_keys($this->sections)));
    }
}
