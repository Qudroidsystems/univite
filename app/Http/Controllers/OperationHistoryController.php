<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\OperationBatch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only view of the operation log. */
class OperationHistoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View operation history')->only('index');
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $batches = OperationBatch::with(['user', 'session'])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('operation_id', 'like', '%' . strtoupper($q) . '%')
                ->orWhereHas('events', fn ($e) => $e->where('label', 'like', "%{$q}%"))))
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->query('module')))
            ->when($request->filled('session'), fn ($query) => $query->where('academic_session_id', $request->query('session')))
            ->when($request->filled('user'), fn ($query) => $query->where('user_id', $request->query('user')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('created_at', '<=', $request->query('to')))
            ->latest('id')->paginate(25)->withQueryString();

        return view('operations.index', [
            'pagetitle' => 'Operation history', 'batches' => $batches,
            'modules'  => OperationBatch::distinct()->orderBy('module')->pluck('module'),
            'sessions' => AcademicSession::orderByDesc('name')->pluck('name', 'id'),
            'users'    => User::whereIn('id', OperationBatch::whereNotNull('user_id')->distinct()->pluck('user_id'))->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /** Anyone with the history permission, and whoever carried out (or may reverse) this operation. */
    public function show(Request $request, OperationBatch $batch): View
    {
        $user = $request->user();
        $handler = \App\Support\Reversals::for($batch);
        $canReverse = $handler && $handler->allowed($user, $batch);
        abort_unless($user->can('View operation history') || $batch->user_id === $user->id || $canReverse, 403);
        $batch->load(['user', 'session', 'related', 'events.user']);

        return view('operations.show', [
            'pagetitle' => 'Operation ' . $batch->operation_id, 'batch' => $batch,
            'followUps' => OperationBatch::where('related_operation_id', $batch->id)->with('user')->get(),
            'reversal' => $canReverse ? $handler->title($batch) : null,
            'reversible' => (bool) $handler,
        ]);
    }
}
