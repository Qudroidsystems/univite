<?php

namespace App\Http\Controllers;

use App\Models\OperationBatch;
use App\Support\Reversals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Reversals\StalePreview;
use RuntimeException;

/**
 * Preview, then confirm, the reversal or correction of a logged operation. The confirmation is refused if anything
 * changed after the preview was shown. The reversal is a new operation linked to the original; nothing is erased.
 */
class ReversalController extends Controller
{
    private function handler(Request $request, OperationBatch $batch)
    {
        $handler = Reversals::for($batch) ?? abort(404, 'This operation cannot be reversed' . (in_array($batch->status, ['reversed', 'corrected'], true) ? ': it has already been ' . $batch->status . '.' : '.'));
        abort_unless($handler->allowed($request->user(), $batch), 403);

        return $handler;
    }

    public function preview(Request $request, OperationBatch $batch): View
    {
        $handler = $this->handler($request, $batch);
        $plan = $handler->plan($batch, $request->query('mode'));

        return view('operations.reverse', [
            'pagetitle' => $handler->title($batch), 'batch' => $batch->load('user', 'session'), 'title' => $handler->title($batch),
            'plan' => $plan, 'counts' => $plan->counts(),
        ]);
    }

    public function run(Request $request, OperationBatch $batch): RedirectResponse
    {
        $handler = $this->handler($request, $batch);
        $data = $request->validate([
            'mode' => ['required', 'string', 'max:20'], 'fingerprint' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:10', 'max:500'], 'confirm' => ['accepted'],
        ], ['reason.min' => 'Explain the reason in a full sentence (at least 10 characters).', 'confirm.accepted' => 'Tick the box to confirm you have checked the preview.']);

        try {
            $new = DB::transaction(function () use ($handler, $batch, $data, $request) {
                $locked = OperationBatch::lockForUpdate()->findOrFail($batch->id);
                if (in_array($locked->status, ['reversed', 'corrected'], true)) {
                    throw new RuntimeException('This operation has already been ' . $locked->status . '.');
                }
                $plan = $handler->plan($locked, $data['mode']);
                if ($plan->fingerprint() !== $data['fingerprint']) {
                    throw new StalePreview();
                }
                if (! $plan->canRun()) {
                    throw new RuntimeException($plan->blockers[0] ?? 'There is nothing that can be reversed.');
                }

                return $handler->run($locked, $plan, $request->user(), trim($data['reason']));
            });
        } catch (StalePreview) {
            return redirect()->route('operations.reverse', [$batch, 'mode' => $data['mode']])->with('error', 'Something changed after the preview was shown. Check the updated preview and confirm again.');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('operations.show', $new)
            ->with('success', "Done. This was recorded as operation {$new->operation_id}, linked to {$batch->operation_id}.");
    }
}
