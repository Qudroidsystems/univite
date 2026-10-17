<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\OperationBatch;
use App\Models\SetupSnapshot;
use App\Services\Setup\SetupSnapshots;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Save the setup as a snapshot, see what restoring one would change, and restore it (a snapshot of what is there is saved first). */
class SetupSnapshotController extends Controller
{
    public function __construct(private SetupSnapshots $snapshots)
    {
        $this->middleware('permission:Manage setup snapshots');
    }

    public function index(Request $request): View
    {
        $sessions = AcademicSession::orderByDesc('name')->get();
        $session = $sessions->firstWhere('id', (int) $request->query('session'));
        $kind = in_array($request->query('kind'), ['manual', 'auto'], true) ? $request->query('kind') : null;

        return view('setup.snapshots', [
            'pagetitle' => 'Setup snapshots', 'sessions' => $sessions, 'session' => $session, 'kind' => $kind,
            'sections' => $this->snapshots->sections(), 'chosen' => (array) $request->query('sections', []),
            'list' => SetupSnapshot::with('session', 'creator')->select(['id', 'label', 'kind', 'academic_session_id', 'sections', 'summary', 'note', 'created_by', 'created_at'])
                ->when($request->filled('session'), fn ($q) => $q->where(fn ($w) => $w->where('academic_session_id', $session?->id)->orWhereNull('academic_session_id')))
                ->when($kind, fn ($q) => $q->where('kind', $kind))
                ->latest('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'session' => ['nullable', 'exists:academic_sessions,id'],
            'sections' => ['required', 'array', 'min:1'], 'sections.*' => ['in:' . implode(',', array_keys($this->snapshots->sections()))],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['sections.required' => 'Tick at least one part of the setup to save.']);

        try {
            $snap = $this->snapshots->take($data['label'], filled($data['session'] ?? null) ? AcademicSession::find($data['session']) : null,
                $data['sections'], $request->user(), 'manual', $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('setup.snapshots.show', $snap)->with('success', "Snapshot “{$snap->label}” saved.");
    }

    public function show(Request $request, SetupSnapshot $snapshot): View
    {
        $chosen = array_values(array_intersect($snapshot->sections ?? [], (array) $request->query('sections', $snapshot->sections ?? [])));
        try {
            $plans = $this->snapshots->preview($snapshot, $snapshot->sections ?? []);
            $problem = null;
        } catch (RuntimeException $e) {
            [$plans, $problem] = [[], $e->getMessage()];
        }

        $restores = OperationBatch::where('type', 'snapshot_restore')->with('user')->latest('id')->limit(200)->get()
            ->filter(fn ($b) => ($b->meta['snapshot_id'] ?? null) === $snapshot->id)->take(20);

        return view('setup.snapshot', [
            'pagetitle' => 'Snapshot: ' . $snapshot->label, 'snap' => $snapshot->load('session', 'creator'), 'sections' => $this->snapshots->sections(),
            'chosen' => $chosen, 'plans' => $plans, 'problem' => $problem,
            'restores' => $restores,
            'stillSaved' => SetupSnapshot::whereIn('id', $restores->map(fn ($b) => $b->meta['saved_first_as'] ?? null)->filter())->pluck('id', 'id'),
            'madeBy' => OperationBatch::where('type', 'snapshot_restore')->get()->first(fn ($b) => ($b->meta['saved_first_as'] ?? null) === $snapshot->id)
                ?? OperationBatch::where('type', 'session_clone')->get()->first(fn ($b) => ($b->meta['saved_first_as'] ?? null) === $snapshot->id),
        ]);
    }

    public function restore(Request $request, SetupSnapshot $snapshot): RedirectResponse
    {
        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1'], 'sections.*' => ['string'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['sections.required' => 'Tick at least one part to restore.', 'reason.required' => 'Say why the setup is being restored.']);

        try {
            ['batch' => $batch, 'before' => $before] = $this->snapshots->restore($snapshot, $data['sections'], $request->user(), $data['reason']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('setup.snapshots.show', $snapshot)->with('success',
            "Restored: {$batch->success_count} change(s)" . ($batch->skipped_count ? ", {$batch->skipped_count} left in place (see below)" : '')
            . ". Operation ID {$batch->operation_id}. What was there before is saved as “{$before->label}”, so this can be undone by restoring that.");
    }

    public function download(SetupSnapshot $snapshot): StreamedResponse
    {
        $name = 'setup-snapshot-' . $snapshot->id . '-' . $snapshot->created_at->format('Ymd-His') . '.json';
        $body = json_encode([
            'label' => $snapshot->label, 'kind' => $snapshot->kind, 'session' => $snapshot->session?->name, 'taken_at' => $snapshot->created_at->toIso8601String(),
            'taken_by' => $snapshot->creator?->name, 'note' => $snapshot->note, 'summary' => $snapshot->summary, 'data' => $snapshot->data(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response()->streamDownload(fn () => print($body), $name, ['Content-Type' => 'application/json']);
    }

    public function destroy(SetupSnapshot $snapshot): RedirectResponse
    {
        $snapshot->delete();

        return redirect()->route('setup.snapshots.index')->with('success', "Snapshot “{$snapshot->label}” deleted.");
    }
}
