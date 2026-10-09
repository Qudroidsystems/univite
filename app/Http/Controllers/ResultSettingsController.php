<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\ResultService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResultSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View result settings')->only('index');
        $this->middleware('permission:Edit result settings')->only('update');
    }

    public function index(): View
    {
        return view('results.settings', ['pagetitle' => 'Result settings', 'ca' => ResultService::caMax(), 'exam' => ResultService::examMax()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate(['ca_max' => ['required', 'numeric', 'between:0,100'], 'exam_max' => ['required', 'numeric', 'between:0,100']]);
        if (abs($d['ca_max'] + $d['exam_max'] - 100) > 0.001) {
            return back()->withErrors(['exam_max' => 'CA and exam marks must add up to 100.'])->withInput();
        }
        Setting::put('results.ca_max', (string) $d['ca_max']);
        Setting::put('results.exam_max', (string) $d['exam_max']);

        return back()->with('success', 'Result settings saved.');
    }
}
