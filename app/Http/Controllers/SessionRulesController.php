<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\SessionSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SessionRulesController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View session rules')->only('index');
        $this->middleware('permission:Edit session rules')->only('update');
    }

    public function index(): View
    {
        return view('sessions.rules', ['pagetitle' => 'Session rules', 's' => SessionSettings::all(), 'labels' => SessionSettings::LABELS]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['late_registration_days' => ['required', 'integer', 'between:1,120']]);
        foreach (array_keys(SessionSettings::LABELS) as $flag) {
            Setting::put("session.$flag", $request->boolean($flag) ? '1' : '0');
        }
        Setting::put('session.late_registration_days', (string) $data['late_registration_days']);

        return back()->with('success', 'Session rules saved.');
    }
}
