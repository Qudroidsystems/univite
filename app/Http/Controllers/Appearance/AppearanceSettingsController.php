<?php

namespace App\Http\Controllers\Appearance;

use App\Http\Controllers\Controller;
use App\Services\OperationAudit;
use App\Support\Appearance;
use App\Support\AppearanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The school's default font, text size and look for the portal and the mobile app. */
class AppearanceSettingsController extends Controller
{
    public function __construct(private AppearanceSettings $settings, private Appearance $appearance, private OperationAudit $audit)
    {
        $this->middleware('permission:Edit appearance settings');
    }

    public function edit(): View
    {
        return view('appearance.settings', [
            'pagetitle' => 'Appearance', 'group' => $this->settings, 'values' => $this->settings->all(), 'fonts' => Appearance::FONTS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $changed = $this->settings->save($request->validate($this->settings->rules()));
        if ($changed) {
            $this->audit->note('appearance_settings', 'appearance', $request->user(), 'settings_changed', null, array_map(fn ($c) => $c[0], $changed), array_map(fn ($c) => $c[1], $changed));
        }

        return back()->with('success', 'Saved. Everyone who has not chosen their own font and size now sees these.');
    }
}
