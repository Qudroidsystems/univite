<?php

namespace App\Http\Controllers;

use App\Data\Sessions\SemesterPair;
use App\Models\AcademicSession;
use App\Models\OperationBatch;
use App\Models\SemesterType;
use App\Models\SetupSnapshot;
use App\Services\SessionCloneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Copy setup: one session into another, into a new session made from it, or one semester into another. Preview first,
 * then confirm. The destination is saved as a snapshot first, every copy has an operation ID and can be undone.
 */
class SessionCloneController extends Controller
{
    public const MODES = [
        'session' => ['Session → session', 'Copy a session\'s setup into another session that already exists.', 'ri-file-copy-2-line'],
        'new' => ['Start a new session', 'Make the next session from this one (same semesters, dates a year on) and copy its setup in.', 'ri-calendar-2-line'],
        'semester' => ['Semester → semester', 'Copy one semester\'s setup into another semester, in the same session or another one.', 'ri-split-cells-horizontal'],
    ];

    public function __construct(private SessionCloneService $clone)
    {
        $this->middleware('permission:Copy session setup')->only(['index', 'run']);
        $this->middleware('permission:Undo session copies')->only('undo');
    }

    public function index(Request $request): View
    {
        $sessions = AcademicSession::orderByDesc('name')->get();
        $types = SemesterType::orderBy('sort_order')->get();
        $mode = array_key_exists($request->query('mode'), self::MODES) ? $request->query('mode') : 'session';
        $source = $sessions->firstWhere('id', (int) $request->query('source'));
        $dest = $sessions->firstWhere('id', (int) $request->query('dest'));
        $pair = $this->pair($request, $types);
        $newName = (string) $request->query('new_name', $source ? $this->nextName($source->name) : '');
        $chosen = (array) $request->query('categories', []);

        $preview = null;
        if ($source && $request->boolean('preview')) {
            $preview = match ($mode) {
                'new' => $this->clone->previewNew($source, $newName, $chosen),
                'semester' => $dest && $pair ? $this->clone->preview($source, $dest, $chosen, $pair) : null,
                default => $dest ? $this->clone->preview($source, $dest, $chosen) : null,
            };
        }

        return view('sessions.clone', [
            'pagetitle' => 'Copy session setup', 'modes' => self::MODES, 'mode' => $mode,
            'sessions' => $sessions, 'types' => $types, 'source' => $source, 'dest' => $dest, 'pair' => $pair, 'newName' => $newName,
            'chosen' => $chosen, 'categories' => SessionCloneService::CATEGORIES, 'semesterCategories' => SessionCloneService::SEMESTER_CATEGORIES,
            'preview' => $preview,
            'history' => OperationBatch::where('type', 'session_clone')->with(['user', 'session'])->latest('id')->limit(15)->get(),
            'savedFirst' => SetupSnapshot::whereIn('id', OperationBatch::where('type', 'session_clone')->latest('id')->limit(15)->get()
                ->map(fn ($b) => $b->meta['saved_first_as'] ?? null)->filter())->pluck('id', 'id'),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', 'in:' . implode(',', array_keys(self::MODES))],
            'source' => ['required', 'exists:academic_sessions,id'],
            'dest' => ['required_unless:mode,new', 'nullable', 'exists:academic_sessions,id'],
            'new_name' => ['required_if:mode,new', 'nullable', 'string', 'max:20'],
            'source_type' => ['required_if:mode,semester', 'nullable', 'exists:semester_types,id'],
            'dest_type' => ['required_if:mode,semester', 'nullable', 'exists:semester_types,id'],
            'categories' => [$request->input('mode') === 'new' ? 'nullable' : 'required', 'array'], 'categories.*' => ['string'],
        ], ['new_name.required_if' => 'Write the name of the new session, e.g. 2026/2027.']);
        $mode = $data['mode'] ?? 'session';
        $source = AcademicSession::findOrFail($data['source']);
        $categories = $data['categories'] ?? [];

        try {
            if ($mode === 'new') {
                ['session' => $dest, 'batch' => $batch] = $this->clone->runNew($source, trim($data['new_name']), $categories, $request->user());

                return redirect()->route('sessions.clone', ['source' => $source->id, 'dest' => $dest->id])
                    ->with('success', "{$dest->name} was created as a draft with " . $dest->semesters()->count() . ' semester(s)'
                        . ($batch ? ", and {$batch->success_count} item(s) of setup were copied into it. Operation ID {$batch->operation_id}." : '.'));
            }
            $pair = $mode === 'semester' ? new SemesterPair(SemesterType::findOrFail($data['source_type']), SemesterType::findOrFail($data['dest_type'])) : null;
            $batch = $this->clone->run($source, AcademicSession::findOrFail($data['dest']), $categories, $request->user(), $pair);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('sessions.clone', array_filter(['mode' => $mode, 'source' => $data['source'], 'dest' => $data['dest'], 'source_type' => $data['source_type'] ?? null, 'dest_type' => $data['dest_type'] ?? null]))
            ->with('success', "Copied {$batch->success_count} item(s) ({$batch->skipped_count} already there). Operation ID {$batch->operation_id}."
                . (($batch->meta['saved_first_as'] ?? null) ? ' What was there before is saved as a snapshot.' : ''));
    }

    public function undo(Request $request, OperationBatch $batch): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $result = $this->clone->undo($batch, $request->user(), $request->input('reason'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $msg = "Undid {$result['undone']} item(s). Operation ID {$result['batch']->operation_id}.";
        if ($result['kept']) {
            $msg .= ' Kept: ' . implode(' ', array_unique($result['kept']));
        }

        return back()->with($result['kept'] && ! $result['undone'] ? 'error' : 'success', $msg);
    }

    private function pair(Request $request, $types): ?SemesterPair
    {
        $from = $types->firstWhere('id', (int) $request->query('source_type'));
        $to = $types->firstWhere('id', (int) $request->query('dest_type'));

        return $from && $to ? new SemesterPair($from, $to) : null;
    }

    /** 2025/2026 → 2026/2027 */
    private function nextName(string $name): string
    {
        return preg_match('/^(\d{4})\/(\d{4})$/', $name, $m) ? ($m[1] + 1) . '/' . ($m[2] + 1) : '';
    }
}
