<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Models\ClinicVisit;
use Illuminate\View\View;

/** Opened from the QR code on an excuse note so a lecturer can confirm it is genuine. Shows dates only. */
class ExcuseCheckController extends Controller
{
    public function show(string $token): View
    {
        return view('health.excuse-check', ['pagetitle' => 'Excuse note check', 'visit' => ClinicVisit::with('user.student')->where('excuse_token', $token)->first()]);
    }
}
