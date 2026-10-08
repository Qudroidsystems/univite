<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Setting;
use App\Services\ApplicationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View application settings')->only('edit');
        $this->middleware('permission:Edit application settings')->only('update');
    }

    public function edit(): View
    {
        return view('applicants.settings', [
            'pagetitle' => 'Application Settings',
            'sessions'  => AcademicSession::orderByDesc('name')->pluck('name', 'id')->all(),
            'open'      => ApplicationSettings::isOpen(),
            'enabled'   => Setting::get('application.open', '0') === '1',
            'sessionId' => Setting::get('application.session_id'),
            'deadline'  => ApplicationSettings::deadline()?->toDateString(),
            'fee'       => ApplicationSettings::fee(),
            'minScore'  => ApplicationSettings::minUtmeScore(),
            'instructions' => ApplicationSettings::instructions(),
            'paymentInstructions' => ApplicationSettings::paymentInstructions(),
            'acceptanceItemId' => ApplicationSettings::acceptanceItemId(),
            'billItems' => \App\Models\BillItem::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'open'                 => ['nullable', 'boolean'],
            'academic_session_id'  => ['nullable', 'exists:academic_sessions,id', 'required_if:open,1'],
            'deadline'             => ['nullable', 'date'],
            'fee'                  => ['required', 'numeric', 'min:0', 'max:10000000'],
            'min_utme_score'       => ['required', 'integer', 'between:0,400'],
            'instructions'         => ['nullable', 'string', 'max:5000'],
            'payment_instructions' => ['nullable', 'string', 'max:2000'],
            'acceptance_item_id'   => ['nullable', 'exists:bill_items,id'],
        ], ['academic_session_id.required_if' => 'Choose the session applicants are applying for before opening applications.']);

        Setting::put('application.open', $request->boolean('open') ? '1' : '0');
        Setting::put('application.session_id', $data['academic_session_id'] ?? null);
        Setting::put('application.deadline', $data['deadline'] ?? null);
        Setting::put('application.fee', (string) $data['fee']);
        Setting::put('application.min_utme_score', (string) $data['min_utme_score']);
        Setting::put('application.instructions', $data['instructions'] ?? null);
        Setting::put('onboarding.acceptance_item_id', $data['acceptance_item_id'] ?? null);
        Setting::put('application.payment_instructions', $data['payment_instructions'] ?? null);

        return back()->with('success', 'Application settings saved.');
    }
}
