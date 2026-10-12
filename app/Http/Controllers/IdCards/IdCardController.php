<?php

namespace App\Http\Controllers\IdCards;

use App\Data\Campus\Audience;
use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Models\User;
use App\Services\Campus\AudienceResolver;
use App\Services\Campus\PersonFinder;
use App\Services\IdCards\IdCardService;
use App\Services\IdCards\IdCardSettings;
use App\Services\OperationAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** The identity card register: issuing (one person or a group), printing, handing over, replacing and cancelling. */
class IdCardController extends Controller
{
    public function __construct(
        private IdCardService $cards,
        private IdCardSettings $settings,
        private PersonFinder $people,
        private AudienceResolver $audiences,
        private OperationAudit $audit,
    ) {
        $this->middleware('permission:View identity cards|Issue identity cards')->only('index');
        $this->middleware('permission:Issue identity cards')->only('issue', 'approve', 'batch', 'print', 'printed', 'collected', 'photo');
        $this->middleware('permission:Cancel identity cards')->only('cancel');
        $this->middleware('permission:Set identity card design')->only('settings', 'saveSettings');
    }

    public function index(Request $request): View
    {
        $status = $request->query('status', 'active');
        $q = IdCard::with('user.student', 'user.staffProfile', 'invoice')
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when($request->query('kind'), fn ($w, $k) => $w->where('kind', $k))
            ->when($request->query('printed') === 'no', fn ($w) => $w->whereNull('printed_at'))
            ->when($request->query('printed') === 'uncollected', fn ($w) => $w->whereNotNull('printed_at')->whereNull('collected_at'))
            ->when(trim((string) $request->query('q')), function ($w, $t) {
                $w->where(fn ($x) => $x->where('serial', 'like', "%{$t}%")->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%"))
                    ->orWhereHas('user.student', fn ($s) => $s->where('matric_number', 'like', "%{$t}%")->orWhere('last_name', 'like', "%{$t}%"))
                    ->orWhereHas('user.staffProfile', fn ($s) => $s->where('staff_number', 'like', "%{$t}%")));
            });

        return view('idcards.index', [
            'pagetitle' => 'Identity Cards', 'cards' => $q->latest('id')->paginate(30)->withQueryString(), 'status' => $status,
            'counts' => IdCard::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'unprinted' => IdCard::where('status', 'active')->whereNull('printed_at')->count(),
        ]);
    }

    /** Issue a card to one person (also approves their request, if they made one). */
    public function issue(Request $request): RedirectResponse
    {
        $d = $request->validate(['person' => ['required', 'string', 'max:100'], 'reason' => ['nullable', 'in:' . implode(',', array_keys(IdCard::REASONS))]]);
        $user = $this->people->find($d['person']);
        if (! $user) {
            return back()->withInput()->with('error', 'Nobody found with that matric number, staff number or email.');
        }
        try {
            $card = $this->cards->issue($user, $request->user(), $d['reason'] ?? 'first');
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('id-cards.print', ['ids' => $card->id])->with('success', 'Card ' . $card->serial . ' issued to ' . $card->snap('name') . '.');
    }

    /** Issue the card someone asked for (a replacement fee, if charged, must be paid first). */
    public function approve(Request $request, IdCard $card): RedirectResponse
    {
        abort_unless($card->status === 'requested', 404);
        try {
            $card = $this->cards->issue($card->user, $request->user(), $card->reason);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('id-cards.print', ['ids' => $card->id])->with('success', 'Card ' . $card->serial . ' issued to ' . $card->snap('name') . '.');
    }

    /** Issue cards for a whole group, for example all new 100 level students. */
    public function batch(Request $request): RedirectResponse
    {
        $d = $request->validate(Audience::rules() + ['only_without' => ['nullable', 'boolean']]);
        $audience = Audience::fromArray($d['audience']);
        if ($audience->who === 'everyone') {
            return back()->withInput()->with('error', 'Issue student and staff cards separately.');
        }
        $result = $this->cards->issueFor($audience, $request->user(), (bool) ($d['only_without'] ?? true));
        $message = $result->issued() . ' card(s) issued' . ($result->alreadyHad ? ', ' . $result->alreadyHad . ' already had a valid card' : '') . ($result->skipped ? ', ' . count($result->skipped) . ' could not be issued (listed below)' : '') . '.';

        return redirect()->route('id-cards.index', ['status' => 'active', 'printed' => 'no'])->with('success', $message)->with('skipped', $result->skipped);
    }

    public function print(Request $request): View
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        $cards = IdCard::with('user')->where('status', 'active')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->whereNull('printed_at')->when($request->query('kind'), fn ($w, $k) => $w->where('kind', $k)))
            ->orderBy('kind')->orderBy('id')->limit(500)->get();

        return view('idcards.print', ['pagetitle' => 'Print identity cards', 'cards' => $cards, 'settings' => $this->settings]);
    }

    public function printed(Request $request): RedirectResponse
    {
        $d = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);

        return redirect()->route('id-cards.index', ['printed' => 'uncollected'])->with('success', $this->cards->markPrinted($d['ids'], $request->user()) . ' card(s) marked printed.');
    }

    public function collected(Request $request, IdCard $card): RedirectResponse
    {
        try {
            $this->cards->markCollected($card, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recorded that ' . $card->snap('name') . ' collected the card.');
    }

    public function cancel(Request $request, IdCard $card): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->cards->cancel($card, $d['reason'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Cancelled. Scanning this card now shows it is not valid.');
    }

    /** A passport photograph for a member of staff (students' photos are on their student record). */
    public function photo(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->staffProfile !== null, 404);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048']]);
        $path = $request->file('photo')->store('avatars', 'public');
        $user->update(['avatar' => basename($path)]);

        return back()->with('success', 'Photograph saved for ' . $user->name . '.');
    }

    public function settings(): View
    {
        return view('idcards.settings', ['pagetitle' => 'Identity card design', 'group' => $this->settings, 'values' => $this->settings->all()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $changed = $this->settings->save($request->validate($this->settings->rules()));
        if ($changed) {
            $this->audit->note('id_card_settings', 'id_cards', $request->user(), 'settings_changed', null, array_map(fn ($c) => $c[0], $changed), array_map(fn ($c) => $c[1], $changed));
        }

        return back()->with('success', 'Saved.');
    }
}
