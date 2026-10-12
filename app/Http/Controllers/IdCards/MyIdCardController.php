<?php

namespace App\Http\Controllers\IdCards;

use App\Http\Controllers\Controller;
use App\Models\IdCard;
use App\Services\IdCards\IdCardService;
use App\Services\IdCards\IdCardSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** A student's or staff member's own identity card, and asking for a new one. */
class MyIdCardController extends Controller
{
    public function __construct(private IdCardService $cards, private IdCardSettings $settings)
    {
    }

    public function show(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->student || $user->staffProfile, 403, 'This page is for students and staff.');

        return view('idcards.mine', [
            'pagetitle' => 'My Identity Card', 'card' => $this->cards->current($user), 'pending' => $this->cards->pendingRequest($user)?->load('invoice'),
            'problem' => $this->cards->problem($user), 'settings' => $this->settings,
            'history' => IdCard::where('user_id', $user->id)->whereIn('status', ['replaced', 'cancelled'])->latest('id')->get(),
        ]);
    }

    public function request(Request $request): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'in:' . implode(',', array_keys(IdCard::REASONS))], 'note' => ['nullable', 'string', 'max:1000']]);
        try {
            $card = $this->cards->request($request->user(), $d['reason'], $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $card->invoice ? 'Request received. Pay the replacement fee under My Fees; the card is issued once it is paid.' : 'Request received. You will be told when your card is ready to collect.');
    }

    public function withdraw(Request $request): RedirectResponse
    {
        $card = $this->cards->pendingRequest($request->user());
        abort_unless($card !== null, 404);
        try {
            $this->cards->withdrawRequest($card);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Request withdrawn.');
    }
}
