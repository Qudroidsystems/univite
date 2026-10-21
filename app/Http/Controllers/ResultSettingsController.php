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
        return view('results.settings', [
            'pagetitle' => 'Result settings', 'ca' => ResultService::caMax(), 'exam' => ResultService::examMax(),
            'complaintDays' => \App\Services\ResultComplaintService::windowDays(),
            'absentPolicy' => ResultService::absentPolicy(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate(['ca_max' => ['required', 'numeric', 'between:0,100'], 'exam_max' => ['required', 'numeric', 'between:0,100'],
            'complaint_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'absent_policy' => ['nullable', 'in:incomplete,zero']]);
        if (abs($d['ca_max'] + $d['exam_max'] - 100) > 0.001) {
            return back()->withErrors(['exam_max' => 'CA and exam marks must add up to 100.'])->withInput();
        }
        Setting::put('results.ca_max', (string) $d['ca_max']);
        Setting::put('results.exam_max', (string) $d['exam_max']);
        if ($request->filled('absent_policy')) {
            Setting::put('results.absent_policy', $d['absent_policy']);
        }
        if ($request->filled('complaint_days')) {
            Setting::put('results.complaint_days', (string) $d['complaint_days']);
        }

        return back()->with('success', 'Result settings saved.');
    }
}
