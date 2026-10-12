<?php

namespace App\Http\Controllers\IdCards;

use App\Http\Controllers\Controller;
use App\Services\IdCards\IdCardService;
use Illuminate\View\View;

/** Opened by scanning the QR code on an identity card. Public: security staff and outside bodies can check a card. */
class CardCheckController extends Controller
{
    public function __construct(private IdCardService $cards)
    {
    }

    public function show(string $token): View
    {
        return view('idcards.check', ['pagetitle' => 'Identity card check', 'check' => $this->cards->check($token)]);
    }
}
