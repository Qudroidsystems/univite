<?php

namespace App\Support;

use App\Data\Appearance\AppearancePreferences;

/**
 * The school's default look for the portal and the mobile app (Settings → Appearance). Each person can change most of
 * it for themselves under "Display & reading"; see Appearance.
 */
class AppearanceSettings extends SettingsGroup
{
    protected function prefix(): string
    {
        return 'appearance';
    }

    public function fields(): array
    {
        $fonts = collect(Appearance::FONTS)->map(fn ($f) => $f[0])->all();
        $sizes = Appearance::SIZES;

        return [
            'text' => ['Text', 'heading', null, 'The font and text size everyone sees until they choose their own. Printed documents keep their own layout.'],
            'font' => ['Font', 'select', 'poppins', null, $fonts],
            'size' => ['Text size', 'select', 15, 'The portal used 13 before; 15 is easier to read for most people.', $sizes],
            'heading_font' => ['Font for headings', 'select', 'same', 'Page titles and card headings.', ['same' => 'Same as the text'] + $fonts],

            'look' => ['Screen', 'heading', null, null],
            'theme' => ['Light or dark', 'select', 'light', null, AppearancePreferences::THEMES],
            'menu' => ['Menu on wide screens', 'select', 'side', null, AppearancePreferences::MENUS],
            'density' => ['Spacing', 'select', 'comfortable', null, AppearancePreferences::DENSITIES],
            'line_spacing' => ['Line spacing', 'select', 'normal', null, AppearancePreferences::LINE_SPACINGS],

            'choice' => ['What each person may change', 'heading', null, 'Light or dark, the menu position, spacing and motion are always each person\'s choice.'],
            'users_choose_font' => ['Each person may choose their own font', 'switch', true, null],
            'users_choose_size' => ['Each person may choose their own text size', 'switch', true, null],
        ];
    }
}
