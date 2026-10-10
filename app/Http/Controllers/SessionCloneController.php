<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\OperationBatch;
use App\Services\SessionCloneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Copy one session's setup into another: preview first, then confirm. Every copy has an operation ID and can be undone. */
class SessionCloneController extends Controller
{
    public function __construct(private SessionCloneService $clone)
    {
        $this->middleware('permission:Copy session setup')->only(['index', 'run']);
        $this->middleware('permission:Undo session copies')->only('undo');
    }

    public function index(Request $request): View
    {
        $sessions = AcademicSession::orderByDesc('name')->get();
        $source = $sessions->firstWhere('id', (int) $request->query('source'));
        $dest = $sessions->firstWhere('id', (int) $request->query('dest'));
        $chosen = (array) $request->query('categories', []);
        $preview = ($source && $dest && $request->boolean('preview')) ? $this->clone->preview($source, $dest, $chosen) : null;

        return view('sessions.clone', [
            'pagetitle' => 'Copy session setup',
            'sessions'  => $sessions, 'source' => $source, 'dest' => $dest, 'chosen' => $chosen,
            'categories' => SessionCloneService::CATEGORIES, 'preview' => $preview,
            'history'   => OperationBatch::whereIn('type', ['session_clone'])->with(['user', 'session'])->latest('id')->limit(15)->get(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'exists:academic_sessions,id'], 'dest' => ['required', 'exists:academic_sessions,id'],
            'categories' => ['required', 'array', 'min:1'], 'categories.*' => ['string'],
        ]);

        try {
            $batch = $this->clone->run(AcademicSession::findOrFail($data['source']), AcademicSession::findOrFail($data['dest']), $data['categories'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('sessions.clone', ['source' => $data['source'], 'dest' => $data['dest']])
            ->with('success', "Copied {$batch->success_count} item(s) ({$batch->skipped_count} already there). Operation ID {$batch->operation_id}.");
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
}
