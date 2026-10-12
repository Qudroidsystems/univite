<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\LibraryLoan;
use App\Services\Campus\PersonFinder;
use App\Services\Library\LibraryService;
use App\Services\Library\LibrarySettings;
use App\Services\OperationAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** The circulation desk: lending, returns, renewals, lost books, the loans register and fines. */
class DeskController extends Controller
{
    public function __construct(
        private LibraryService $library,
        private LibrarySettings $settings,
        private PersonFinder $people,
        private OperationAudit $audit,
    ) {
        $this->middleware('permission:Issue library loans')->only('desk', 'issue', 'giveBack', 'renew', 'lost');
        $this->middleware('permission:View library loans|Issue library loans')->only('loans', 'fines');
        $this->middleware('permission:Record library fine payments')->only('finePaid');
        $this->middleware('permission:Waive library fines')->only('waive');
        $this->middleware('permission:Set library policy')->only('settings', 'saveSettings');
    }

    public function desk(Request $request): View
    {
        $borrower = $request->query('borrower') ? $this->people->find($request->query('borrower')) : null;

        return view('library.desk', [
            'pagetitle' => 'Library desk', 'borrower' => $borrower, 'standing' => $borrower ? $this->library->standing($borrower) : null,
            'loans' => $borrower ? LibraryLoan::with('copy.item', 'invoice')->where('user_id', $borrower->id)->where(fn ($q) => $q->whereNull('returned_at')->orWhereIn('fine_status', ['owed', 'invoiced']))->latest('id')->get() : collect(),
            'label' => $borrower ? $this->people->label($borrower) : null,
            'today' => LibraryLoan::with('copy.item', 'user')->where(fn ($q) => $q->whereDate('issued_at', today())->orWhereDate('returned_at', today()))->latest('updated_at')->limit(20)->get(),
        ]);
    }

    public function issue(Request $request): RedirectResponse
    {
        $d = $request->validate(['borrower' => ['required', 'string', 'max:100'], 'accession' => ['required', 'string', 'max:50']]);
        $user = $this->people->find($d['borrower']);
        $copy = $this->library->findCopy($d['accession']);
        if (! $user || ! $copy) {
            return back()->withInput()->with('error', ! $user ? 'No student or staff member found with that number.' : 'No copy has accession number ' . $d['accession'] . '.');
        }
        try {
            $loan = $this->library->issue($copy, $user, $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('library.desk', ['borrower' => $d['borrower']])->with('error', $e->getMessage());
        }

        return redirect()->route('library.desk', ['borrower' => $d['borrower']])->with('success', '"' . $copy->item->title . '" lent to ' . $this->people->label($user) . ', due back ' . $loan->due_at->format('D j M Y') . '.');
    }

    public function giveBack(Request $request): RedirectResponse
    {
        $d = $request->validate(['accession' => ['required', 'string', 'max:50']]);
        $copy = $this->library->findCopy($d['accession']);
        if (! $copy) {
            return back()->with('error', 'No copy has accession number ' . $d['accession'] . '.');
        }
        try {
            $r = $this->library->giveBack($copy, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $msg = '"' . $copy->item->title . '" returned by ' . $this->people->label($r->loan->user) . '.';
        if ($r->fine > 0) {
            $msg .= ' ' . $r->daysLate . ' day(s) late: fine ₦' . number_format($r->fine, 2) . ($r->loan->fine_status === 'invoiced' ? ', added to their fees.' : ', to be paid at the desk.');
        }
        if ($r->heldFor) {
            $msg .= ' Keep it aside: it is reserved for ' . $this->people->label($r->heldFor->user) . '.';
        }

        return back()->with($r->heldFor || $r->fine > 0 ? 'warning' : 'success', $msg);
    }

    public function renew(LibraryLoan $loan): RedirectResponse
    {
        try {
            $this->library->renew($loan);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Renewed until ' . $loan->due_at->format('j M Y') . '.');
    }

    public function lost(Request $request, LibraryLoan $loan): RedirectResponse
    {
        $d = $request->validate(['charge' => ['nullable', 'numeric', 'min:0']]);
        try {
            $loan = $this->library->markLost($loan, $request->user(), isset($d['charge']) ? (float) $d['charge'] : null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recorded as lost.' . ($loan->fine > 0 ? ' Charge: ₦' . number_format((float) $loan->fine, 2) . '.' : ''));
    }

    public function loans(Request $request): View
    {
        $show = $request->query('show', 'out');
        $loans = LibraryLoan::with('copy.item', 'user.student', 'user.staffProfile')
            ->when($show === 'out', fn ($q) => $q->whereNull('returned_at'))
            ->when($show === 'overdue', fn ($q) => $q->whereNull('returned_at')->where('due_at', '<', now()))
            ->when($show === 'returned', fn ($q) => $q->whereNotNull('returned_at'))
            ->when(trim((string) $request->query('q')), fn ($q, $t) => $q->where(fn ($w) => $w->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$t}%"))
                ->orWhereHas('user.student', fn ($s) => $s->where('matric_number', $t))->orWhereHas('copy', fn ($c) => $c->where('accession_number', $t))
                ->orWhereHas('copy.item', fn ($i) => $i->where('title', 'like', "%{$t}%"))))
            ->orderBy($show === 'returned' ? 'returned_at' : 'due_at', $show === 'returned' ? 'desc' : 'asc')->paginate(40)->withQueryString();

        return view('library.loans', ['pagetitle' => 'Loans', 'loans' => $loans, 'show' => $show,
            'overdue' => LibraryLoan::whereNull('returned_at')->where('due_at', '<', now())->count()]);
    }

    public function fines(Request $request): View
    {
        $status = $request->query('status', 'unpaid');
        $loans = LibraryLoan::with('copy.item', 'user', 'invoice')->where('fine', '>', 0)
            ->when($status === 'unpaid', fn ($q) => $q->whereIn('fine_status', ['owed', 'invoiced']))
            ->when($status !== 'unpaid' && $status !== 'all', fn ($q) => $q->where('fine_status', $status))
            ->latest('returned_at')->paginate(40)->withQueryString();

        return view('library.fines', ['pagetitle' => 'Library fines', 'loans' => $loans, 'status' => $status]);
    }

    public function finePaid(Request $request, LibraryLoan $loan): RedirectResponse
    {
        try {
            $this->library->recordPayment($loan, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment of ₦' . number_format((float) $loan->fine, 2) . ' recorded.');
    }

    public function waive(Request $request, LibraryLoan $loan): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        try {
            $this->library->waive($loan, $d['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Fine waived.');
    }

    public function settings(): View
    {
        return view('library.settings', ['pagetitle' => 'Library rules', 'group' => $this->settings, 'values' => $this->settings->all()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $changed = $this->settings->save($request->validate($this->settings->rules()));
        if ($changed) {
            $this->audit->note('library_settings', 'library', $request->user(), 'settings_changed', null, array_map(fn ($c) => $c[0], $changed), array_map(fn ($c) => $c[1], $changed));
        }

        return back()->with('success', 'Saved.');
    }
}
