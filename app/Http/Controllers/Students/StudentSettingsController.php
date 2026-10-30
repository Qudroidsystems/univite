<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Services\OperationAudit;
use App\Support\StudentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Settings → Students: profile updates by students, NIN and JAMB number, referees. */
class StudentSettingsController extends Controller
{
    public function __construct(private StudentSettings $settings, private OperationAudit $audit)
    {
        $this->middleware('permission:Edit student profile settings');
    }

    public function edit(): View
    {
        return view('students.profile-settings', ['pagetitle' => 'Student settings', 'group' => $this->settings, 'values' => $this->settings->all()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $changed = $this->settings->save($request->validate($this->settings->rules()));
        if ($changed) {
            $this->audit->note('student_settings', 'students', $request->user(), 'settings_changed', null, array_map(fn ($c) => $c[0], $changed), array_map(fn ($c) => $c[1], $changed));
        }

        return back()->with('success', 'Student settings saved.');
    }
}
