<?php

namespace App\Http\Controllers\Appearance;

use App\Data\Appearance\AppearancePreferences;
use App\Http\Controllers\Controller;
use App\Support\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** "Display & reading": each person's own font, text size, light or dark, menu position, spacing and motion. */
class MyAppearanceController extends Controller
{
    public function __construct(private Appearance $appearance)
    {
    }

    public function edit(Request $request): View
    {
        return view('appearance.mine', [
            'pagetitle' => 'Display & reading', 'saved' => $this->appearance->saved($request->user()), 'defaults' => $this->appearance->defaults(),
            'fonts' => Appearance::FONTS, 'sizes' => Appearance::SIZES, 'chooseFont' => $this->appearance->usersChooseFont(), 'chooseSize' => $this->appearance->usersChooseSize(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->appearance->save($request->user(), AppearancePreferences::fromArray($request->validate($this->rules())));

        return back()->with('success', 'Saved. The portal and the mobile app now use your choices.');
    }

    /** The topbar's light/dark and menu switches save here, so the choice follows the person to other devices. */
    public function quick(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['sometimes', Rule::in(array_keys(AppearancePreferences::THEMES))],
            'menu' => ['sometimes', Rule::in(array_keys(AppearancePreferences::MENUS))],
        ]);
        $saved = $this->appearance->saved($request->user())->toArray();
        $this->appearance->save($request->user(), AppearancePreferences::fromArray(array_merge($saved, $data)));

        return response()->json(['ok' => true]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->appearance->save($request->user(), new AppearancePreferences());

        return back()->with('success', 'Back to the school\'s settings.');
    }

    /** Rules shared with the mobile API. Empty means "the school's default". */
    public static function rules(): array
    {
        return [
            'font' => ['nullable', Rule::in(array_keys(Appearance::FONTS))],
            'size' => ['nullable', 'integer', Rule::in(array_keys(Appearance::SIZES))],
            'theme' => ['nullable', Rule::in(array_keys(AppearancePreferences::THEMES))],
            'menu' => ['nullable', Rule::in(array_keys(AppearancePreferences::MENUS))],
            'density' => ['nullable', Rule::in(array_keys(AppearancePreferences::DENSITIES))],
            'line_spacing' => ['nullable', Rule::in(array_keys(AppearancePreferences::LINE_SPACINGS))],
            'reduce_motion' => ['nullable', 'boolean'],
        ];
    }
}
