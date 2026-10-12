<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\LibraryHold;
use App\Models\LibraryItem;
use App\Models\LibraryLoan;
use App\Services\Library\LibraryService;
use App\Services\Library\LibrarySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** A borrower's own loans, holds and fines, and searching the catalogue. */
class MyLibraryController extends Controller
{
    public function __construct(private LibraryService $library, private LibrarySettings $settings)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $q = trim((string) $request->query('q'));

        return view('library.mine', [
            'pagetitle' => 'My Library', 'standing' => $this->library->standing($user), 'settings' => $this->settings, 'q' => $q,
            'loans' => LibraryLoan::with('copy.item')->where('user_id', $user->id)->whereNull('returned_at')->orderBy('due_at')->get(),
            'fines' => LibraryLoan::with('copy.item', 'invoice')->where('user_id', $user->id)->where('fine', '>', 0)->latest('returned_at')->limit(20)->get(),
            'holds' => LibraryHold::with('item')->where('user_id', $user->id)->whereIn('status', ['waiting', 'ready'])->get(),
            'history' => LibraryLoan::with('copy.item')->where('user_id', $user->id)->whereNotNull('returned_at')->latest('returned_at')->limit(10)->get(),
            'results' => $q !== '' ? LibraryItem::search($q)->withCount(['copies as available_count' => fn ($c) => $c->where('status', 'available'), 'copies'])->orderBy('title')->limit(40)->get() : collect(),
        ]);
    }

    public function renew(Request $request, LibraryLoan $loan): RedirectResponse
    {
        abort_unless($loan->user_id === $request->user()->id, 404);
        try {
            $this->library->renew($loan, true);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Renewed. Now due ' . $loan->due_at->format('j M Y') . '.');
    }

    public function hold(Request $request, LibraryItem $item): RedirectResponse
    {
        try {
            $this->library->placeHold($item, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'You are on the waiting list for "' . $item->title . '". You will be told when a copy is kept for you.');
    }

    public function cancelHold(Request $request, LibraryHold $hold): RedirectResponse
    {
        abort_unless($hold->user_id === $request->user()->id, 404);
        try {
            $this->library->cancelHold($hold);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Request cancelled.');
    }
}
