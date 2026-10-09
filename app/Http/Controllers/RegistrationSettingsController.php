<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RegistrationSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View registration settings')->only('index');
        $this->middleware('permission:Edit registration settings')->only('update');
    }

    public function index(): View
    {
        return view('registration.settings', ['pagetitle' => 'Registration settings', 's' => RegistrationSettings::all(), 'feeRules' => RegistrationSettings::FEE_RULES]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'min_units' => ['required', 'integer', 'between:0,60'],
            'max_units' => ['required', 'integer', 'between:1,80', 'gte:min_units'],
            'fee_rule'  => ['required', 'in:' . implode(',', array_keys(RegistrationSettings::FEE_RULES))],
        ]);

        foreach (['open', 'require_approval', 'require_clearance', 'force_carryovers', 'check_prerequisites', 'carryover_exempt'] as $flag) {
            Setting::put("registration.$flag", $request->boolean($flag) ? '1' : '0');
        }
        foreach ($data as $k => $v) {
            Setting::put("registration.$k", (string) $v);
        }

        return back()->with('success', 'Registration settings saved.');
    }
}
